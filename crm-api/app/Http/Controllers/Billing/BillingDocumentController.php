<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingCatalogueItem;
use App\Models\BillingDocument;
use App\Models\BillingProfile;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use App\Services\Billing\BillingAuditService;
use App\Services\Billing\BillingCalculationService;
use App\Services\Billing\BillingNumberService;
use App\Services\Billing\BillingPdfService;
use App\Services\Billing\BillingPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillingDocumentController extends Controller
{
    public function __construct(
        private readonly BillingCalculationService $calculator,
        private readonly BillingNumberService $numbers,
        private readonly BillingPdfService $pdf,
        private readonly BillingPermissionService $permissions,
        private readonly BillingAuditService $audit,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $this->require($request, 'view');
        $q = $this->scoped($request);
        $issued = (clone $q)->where('document_type', 'invoice')->whereNotNull('issued_at')->whereNull('voided_at');
        $allocated = DB::table('billing_payment_allocations')->join('billing_documents', 'billing_documents.id', '=', 'billing_payment_allocations.billing_document_id')
            ->where('billing_documents.organization_id', $request->user()->organization_id)->whereNull('billing_payment_allocations.reversed_at')->sum('billing_payment_allocations.amount');

        return response()->json(['data' => [
            'issued_invoices' => (clone $issued)->count(), 'invoiced_total' => (string) (clone $issued)->sum('grand_total'),
            'allocated_total' => number_format((float) $allocated, 2, '.', ''),
            'outstanding_total' => number_format(max(0, (float) (clone $issued)->sum('grand_total') - (float) $allocated), 2, '.', ''),
            'overdue_invoices' => (clone $issued)->whereDate('due_date', '<', today())->where('payment_status', '!=', 'paid')->count(),
            'drafts' => (clone $q)->where('status', 'draft')->count(), 'pending_approvals' => (clone $q)->where('status', 'pending_approval')->count(),
            'recent_documents' => (clone $q)->with(['client:id,first_name,last_name,company'])->latest()->limit(8)->get(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->require($request, 'view');
        $query = $this->scoped($request)->with(['client:id,first_name,last_name,company', 'deal:id,title', 'lead:id,first_name,last_name,company', 'allocations' => fn ($q) => $q->whereNull('reversed_at')]);
        foreach (['document_type', 'status', 'payment_status', 'currency', 'lead_id', 'client_id', 'deal_id', 'assigned_to'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('document_number', 'like', "%{$search}%")->orWhere('customer_snapshot->company_name', 'like', "%{$search}%")->orWhere('customer_snapshot->contact_name', 'like', "%{$search}%"));
        }

        $canChangeDrafts = $this->canChangeDrafts($request);
        $documents = $query->withExists(BillingDocument::DRAFT_LINKS)->latest()
            ->paginate(min(100, max(1, $request->integer('per_page', 20))))
            ->through(fn (BillingDocument $document) => $this->withDraftActions($document, $canChangeDrafts));

        return response()->json($documents);
    }

    public function show(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        $this->assertVisible($request, $billingDocument);

        $billingDocument->load(['items.catalogueItem', 'client', 'deal', 'lead', 'sourceDocument', 'allocations.payment']);
        $billingDocument->loadExists(BillingDocument::DRAFT_LINKS);

        return response()->json(['data' => $this->withDraftActions($billingDocument, $this->canChangeDrafts($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->require($request, 'create_draft');
        $data = $this->validateDocument($request);
        if (! empty($data['idempotency_key']) && ($existing = BillingDocument::withTrashed()
            ->where('organization_id', $request->user()->organization_id)->where('idempotency_key', $data['idempotency_key'])->first())) {
            abort_if($existing->trashed(), 409, 'This billing draft was deleted.');

            return response()->json(['data' => $existing->load('items')]);
        }
        $document = DB::transaction(function () use ($request, $data) {
            [$relations, $snapshot, $assigned] = $this->resolveParties($request, $data);
            $calculation = $this->calculate($request, $data['items']);
            $profile = BillingProfile::where('organization_id', $request->user()->organization_id)->first();
            $taxContext = $data['tax_context'] ?? [];
            if (blank($taxContext['payment_link'] ?? null) && filled($profile?->default_payment_link)) {
                $taxContext['payment_link'] = $profile->default_payment_link;
            }
            $document = BillingDocument::create(array_merge($relations, $calculation['totals'], [
                'organization_id' => $request->user()->organization_id, 'document_type' => $data['document_type'], 'status' => 'draft',
                'currency' => strtoupper($data['currency']), 'customer_snapshot' => $snapshot, 'tax_context' => $taxContext,
                'template' => $data['template'] ?? 'classic', 'issue_date' => $data['issue_date'] ?? null, 'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? $profile?->default_notes, 'footer' => $data['footer'] ?? $profile?->document_footer,
                'terms' => $data['terms'] ?? $profile?->default_terms, 'created_by' => $request->user()->id,
                'assigned_to' => $assigned, 'idempotency_key' => $data['idempotency_key'] ?? null,
            ]));
            $this->replaceItems($document, $data['items'], $calculation['items'], $request);
            $this->audit->record($document->organization_id, $request->user(), 'document.created', $document, null, ['document_type' => $document->document_type], $request);

            return $document;
        });

        return response()->json(['data' => $document->load('items')], 201);
    }

    public function update(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        $this->assertDraftMutationAllowed($request, $billingDocument);
        $data = $this->validateDocument($request, $billingDocument->document_type);
        $updated = DB::transaction(function () use ($request, $data, $billingDocument) {
            $document = $this->lockedDocument($billingDocument);
            $this->assertDraftMutationAllowed($request, $document);
            if ($document->document_type === 'invoice' && ! array_key_exists('lead_id', $data)) {
                $data['lead_id'] = $document->lead_id;
            }
            [$relations, $snapshot, $assigned] = $this->resolveParties($request, $data, $document);
            if ($document->source_document_id && ($relations['client_id'] !== $document->client_id
                || $relations['deal_id'] !== $document->deal_id || $relations['lead_id'] !== $document->lead_id)) {
                throw ValidationException::withMessages(['document' => 'The original Lead, Client and Deal links must be kept on a converted invoice.']);
            }
            $calculation = $this->calculate($request, $data['items']);
            $originalItems = $document->items()->get()->keyBy('id');
            $before = $document->toArray() + ['items' => $originalItems->values()->toArray()];
            $document->fill($relations);
            $taxContext = $data['tax_context'] ?? [];
            unset($taxContext['draft_issue_date_selected']);
            if ($document->source_document_id && (array_key_exists('issue_date', $data)
                ? $data['issue_date'] !== null : ($document->tax_context['draft_issue_date_selected'] ?? false))) {
                $taxContext['draft_issue_date_selected'] = true;
            }
            $changes = ['currency' => strtoupper($data['currency']), 'customer_snapshot' => $snapshot,
                'assigned_to' => $document->isDirty(array_keys($relations)) ? $assigned : $document->assigned_to, 'tax_context' => $taxContext,
                'template' => $data['template'] ?? $document->template, 'due_date' => $data['due_date'] ?? null,
                'terms' => $data['terms'] ?? null, 'notes' => $data['notes'] ?? null,
                'footer' => array_key_exists('footer', $data) ? $data['footer'] : $document->footer];
            if (array_key_exists('issue_date', $data)) {
                $changes['issue_date'] = $data['issue_date'];
            }
            $document->update(array_merge($calculation['totals'], $changes, $this->resetApproval($document)));
            $document->items()->delete();
            $this->replaceItems($document, $data['items'], $calculation['items'], $request, $originalItems);
            $saved = $document->fresh()->load('items');
            $this->audit->record($document->organization_id, $request->user(), 'document.updated', $document, $before, $saved->toArray(), $request);

            return $saved;
        });

        return response()->json(['data' => $updated]);
    }

    private function resetApproval(BillingDocument $document): array
    {
        return $document->status === 'approved'
            ? ['status' => 'draft', 'approved_at' => null, 'approved_by' => null, 'submitted_at' => null]
            : [];
    }

    public function destroy(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        $this->assertDraftMutationAllowed($request, $billingDocument, deleting: true);
        DB::transaction(function () use ($request, $billingDocument) {
            $document = $this->lockedDocument($billingDocument);
            $this->assertDraftMutationAllowed($request, $document, deleting: true);
            $before = $document->load('items')->toArray();
            $document->delete();
            $this->audit->record($document->organization_id, $request->user(), 'document.deleted', $document, $before, ['deleted_at' => $document->deleted_at], $request);
        });

        return response()->json(null, 204);
    }

    private function lockedDocument(BillingDocument $document): BillingDocument
    {
        return BillingDocument::query()->lockForUpdate()->findOrFail($document->id);
    }

    private function canChangeDrafts(Request $request): bool
    {
        $profile = BillingProfile::where('organization_id', $request->user()->organization_id)->first();

        return $this->permissions->allowsDraftChanges($request->user(), $profile);
    }

    private function withDraftActions(BillingDocument $document, bool $allowed): BillingDocument
    {
        $reason = 'Billing draft management requires owner or admin permission.';
        $document->setAttribute('draft_actions', [
            'edit' => $allowed ? $document->draftChangeRestriction() : $reason,
            'delete' => $allowed ? $document->draftChangeRestriction(deleting: true) : $reason,
        ]);
        $document->makeHidden(array_map(fn ($relation) => \Illuminate\Support\Str::snake($relation).'_exists', BillingDocument::DRAFT_LINKS));

        return $document;
    }

    private function assertDraftMutationAllowed(Request $request, BillingDocument $document, bool $deleting = false): void
    {
        $this->assertVisible($request, $document);
        abort_unless($this->canChangeDrafts($request), 403, 'Billing draft management requires owner or admin permission.');
        if ($reason = $document->draftChangeRestriction($deleting)) {
            abort(422, $reason);
        }
    }

    public function submit(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        return DB::transaction(function () use ($request, $billingDocument) {
            $billingDocument = $this->lockedDocument($billingDocument);
            $this->assertSubmittable($request, $billingDocument);
            $profile = BillingProfile::where('organization_id', $billingDocument->organization_id)->first();
            $status = $profile?->approval_required ? 'pending_approval' : 'approved';
            $billingDocument->update(['status' => $status, 'submitted_at' => now(), 'approved_at' => $status === 'approved' ? now() : null, 'approved_by' => $status === 'approved' ? $request->user()->id : null]);
            $this->audit->record($billingDocument->organization_id, $request->user(), 'document.submitted', $billingDocument, null, ['status' => $status], $request);

            return response()->json(['data' => $billingDocument->fresh()]);
        });
    }

    public function approve(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        return DB::transaction(function () use ($request, $billingDocument) {
            $billingDocument = $this->lockedDocument($billingDocument);
            $this->assertVisible($request, $billingDocument);
            $this->require($request, 'issue');
            abort_unless($billingDocument->status === 'pending_approval', 422, 'Only pending documents can be approved.');
            $billingDocument->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $request->user()->id]);
            $this->audit->record($billingDocument->organization_id, $request->user(), 'document.approved', $billingDocument, null, null, $request);

            return response()->json(['data' => $billingDocument->fresh()]);
        });
    }

    public function issue(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        $this->assertVisible($request, $billingDocument);
        $this->require($request, 'issue');
        $path = null;
        try {
            $issued = DB::transaction(function () use ($request, $billingDocument, &$path) {
                $billingDocument = $this->lockedDocument($billingDocument);
                abort_unless(in_array($billingDocument->status, ['draft', 'approved'], true), 422, 'Only drafts or approved documents can be issued.');
                $profile = BillingProfile::where('organization_id', $billingDocument->organization_id)->first();
                if ($profile?->approval_required && $billingDocument->status !== 'approved') {
                    throw ValidationException::withMessages(['status' => 'This document requires approval before issuance.']);
                }
                if (! $billingDocument->items()->exists() || (float) $billingDocument->grand_total < 0) {
                    throw ValidationException::withMessages(['items' => 'At least one valid line item is required.']);
                }
                $this->validateIssuanceSnapshots($billingDocument, $profile);
                // Older converted drafts inherited the Proforma date without a user choosing it.
                $documentDate = ! $billingDocument->source_document_id || ($billingDocument->tax_context['draft_issue_date_selected'] ?? false)
                    ? $billingDocument->issue_date : null;
                $issued = $this->numbers->issue($billingDocument, $request->user(), now(), $documentDate);
                $this->pdf->storedDocument($issued);
                $path = $issued->fresh()->pdf_path;

                return $issued->fresh();
            }, 3);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk((string) config('billing.pdf.disk', 'local'))->delete($path);
            }
            throw $e;
        }

        return response()->json(['data' => $issued]);
    }

    public function convert(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        $this->assertVisible($request, $billingDocument);
        $this->require($request, 'create_draft');
        if ($billingDocument->document_type !== 'proforma' || ! $billingDocument->client_id || ! $billingDocument->deal_id) {
            throw ValidationException::withMessages(['document' => 'An issued Proforma linked to a Client and Deal is required.']);
        }
        $request->validate(['confirm_deal_value_difference' => ['sometimes', 'accepted']]);
        $invoice = DB::transaction(function () use ($request, $billingDocument) {
            $source = BillingDocument::lockForUpdate()->findOrFail($billingDocument->id);
            if ($source->converted_to_document_id) {
                return BillingDocument::findOrFail($source->converted_to_document_id);
            }
            $deal = Deal::withTrashed()->findOrFail($source->deal_id);
            if (abs((float) $deal->value - (float) $source->grand_total) > .009 && ! $request->boolean('confirm_deal_value_difference')) {
                throw ValidationException::withMessages(['confirm_deal_value_difference' => 'Confirm the difference between Deal value and Proforma total.']);
            }
            $invoice = $source->replicate(['uuid', 'document_number', 'issue_date', 'issued_at', 'issued_by', 'pdf_path', 'pdf_sha256', 'submitted_at', 'approved_at', 'approved_by', 'voided_at', 'voided_by', 'void_reason', 'idempotency_key']);
            $invoice->fill(['document_type' => 'invoice', 'status' => 'draft', 'payment_status' => 'unpaid', 'delivery_status' => 'not_sent', 'source_document_id' => $source->id, 'created_by' => $request->user()->id]);
            $invoice->save();
            foreach ($source->items as $item) {
                $copy = $item->replicate();
                $copy->billing_document_id = $invoice->id;
                $copy->save();
            }
            $source->forceFill(['converted_to_document_id' => $invoice->id])->save();
            $this->audit->record($source->organization_id, $request->user(), 'document.converted', $source, null, ['invoice_id' => $invoice->id], $request);

            return $invoice;
        });

        return response()->json(['data' => $invoice->load('items')], 201);
    }

    public function linkCommercial(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        return DB::transaction(function () use ($request, $billingDocument) {
            $billingDocument = $this->lockedDocument($billingDocument);
            $this->assertVisible($request, $billingDocument);
            abort_unless($billingDocument->document_type === 'proforma', 422, 'Only Proforma Invoices can be linked after Lead conversion.');
            if ($billingDocument->issued_at) {
                $this->require($request, 'create_draft');
            } else {
                $this->assertDraftMutationAllowed($request, $billingDocument);
            }
            abort_if($billingDocument->converted_to_document_id, 422, 'This Proforma has already been converted to an Invoice.');
            $data = $request->validate(['client_id' => ['required', 'integer'], 'deal_id' => ['required', 'integer']]);
            $client = Client::where('organization_id', $request->user()->organization_id)->findOrFail($data['client_id']);
            $deal = Deal::where('organization_id', $request->user()->organization_id)->where('client_id', $client->id)->findOrFail($data['deal_id']);
            if ($billingDocument->lead_id && $deal->lead_id !== $billingDocument->lead_id) {
                throw ValidationException::withMessages(['deal_id' => 'The Deal was not created from this Proforma Lead.']);
            }
            if (($billingDocument->client_id && $billingDocument->client_id !== $client->id) || ($billingDocument->deal_id && $billingDocument->deal_id !== $deal->id)) {
                throw ValidationException::withMessages(['document' => 'This Proforma is already linked to a different Client or Deal.']);
            }
            $changes = ['client_id' => $client->id, 'deal_id' => $deal->id, 'assigned_to' => $deal->assigned_to];
            $billingDocument->fill($changes);
            if (! $billingDocument->issued_at && $billingDocument->isDirty()) {
                $billingDocument->fill($this->resetApproval($billingDocument));
            }
            $after = $billingDocument->getDirty();
            if ($after) {
                $before = array_intersect_key($billingDocument->getOriginal(), $after);
                $billingDocument->save();
                $this->audit->record($billingDocument->organization_id, $request->user(), 'document.commercial_linked', $billingDocument, $before, $after, $request);
            }

            return response()->json(['data' => $billingDocument->fresh()]);
        });
    }

    public function void(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        return DB::transaction(function () use ($request, $billingDocument, $data) {
            $billingDocument = $this->lockedDocument($billingDocument);
            $this->assertVisible($request, $billingDocument);
            $this->require($request, 'void');
            abort_if($billingDocument->voided_at, 422, 'Document already voided.');
            $billingDocument->forceFill(['status' => 'void', 'voided_at' => now(), 'voided_by' => $request->user()->id, 'void_reason' => $data['reason']])->save();
            $this->audit->record($billingDocument->organization_id, $request->user(), 'document.voided', $billingDocument, null, ['reason' => $data['reason']], $request);

            return response()->json(['data' => $billingDocument->fresh()]);
        });
    }

    private function validateDocument(Request $request, ?string $forcedType = null): array
    {
        $data = $request->validate([
            'document_type' => [$forcedType ? 'sometimes' : 'required', Rule::in(['proforma', 'invoice'])], 'lead_id' => ['nullable', 'integer'], 'client_id' => ['nullable', 'integer'], 'deal_id' => ['nullable', 'integer'],
            'currency' => ['required', Rule::in(['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD'])], 'issue_date' => ['nullable', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date'], 'template' => ['nullable', Rule::in(BillingPdfService::TEMPLATES)],
            'terms' => ['nullable', 'string'], 'notes' => ['nullable', 'string'], 'footer' => ['nullable', 'string'], 'tax_context' => ['nullable', 'array'], 'idempotency_key' => ['nullable', 'string', 'max:100'],
            'customer_snapshot' => ['nullable', 'array'],
            'customer_snapshot.customer_type' => ['nullable', Rule::in(['individual', 'business'])],
            'customer_snapshot.contact_name' => ['nullable', 'string', 'max:255'], 'customer_snapshot.company_name' => ['nullable', 'string', 'max:255'],
            'customer_snapshot.email' => ['nullable', 'email', 'max:255'], 'customer_snapshot.phone' => ['nullable', 'string', 'max:32'],
            'customer_snapshot.alternative_phone' => ['nullable', 'string', 'max:32'],
            'customer_snapshot.address' => ['nullable', 'string', 'max:2000'], 'customer_snapshot.address_line1' => ['nullable', 'string', 'max:1000'],
            'customer_snapshot.address_line2' => ['nullable', 'string', 'max:1000'], 'customer_snapshot.city' => ['nullable', 'string', 'max:120'],
            'customer_snapshot.district' => ['nullable', 'string', 'max:120'],
            'customer_snapshot.state' => ['nullable', 'string', 'max:120'], 'customer_snapshot.postal_code' => ['nullable', 'string', 'max:20'],
            'customer_snapshot.country' => ['nullable', 'string', 'max:120'], 'customer_snapshot.gstin' => ['nullable', 'string', 'max:32'],
            'customer_snapshot.pan' => ['nullable', 'string', 'max:20'], 'customer_snapshot.website' => ['nullable', 'url', 'max:2048'],
            'customer_snapshot.billing_contact_person' => ['nullable', 'string', 'max:255'], 'customer_snapshot.notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'], 'items.*.id' => ['nullable', 'integer'], 'items.*.catalogue_item_id' => ['nullable', 'integer'], 'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.hsn_sac' => ['nullable', 'string', 'max:32'], 'items.*.quantity' => ['required', 'numeric', 'gt:0'], 'items.*.unit' => ['nullable', 'string', 'max:32'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'], 'items.*.discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])], 'items.*.discount_value' => ['nullable', 'numeric', 'min:0'], 'items.*.gst_rate' => ['nullable', 'numeric', 'between:0,100'], 'items.*.metadata' => ['nullable', 'array'],
        ]);
        $data['document_type'] = $forcedType ?? $data['document_type'];
        if ($data['document_type'] === 'invoice' && (empty($data['client_id']) || empty($data['deal_id']))) {
            throw ValidationException::withMessages(['deal_id' => 'Invoices require an existing Client and Deal.']);
        }
        if ($data['document_type'] === 'proforma' && empty($data['lead_id']) && empty($data['client_id']) && empty($data['deal_id'])) {
            $customer = $data['customer_snapshot'] ?? [];
            if (blank($customer['contact_name'] ?? null) && blank($customer['company_name'] ?? null)) {
                throw ValidationException::withMessages(['customer_snapshot.contact_name' => 'Enter a customer name or company, or select an existing Lead.']);
            }
        }

        return $data;
    }

    private function resolveParties(Request $request, array $data, ?BillingDocument $document = null): array
    {
        $org = $request->user()->organization_id;
        $relations = ['lead_id' => null, 'client_id' => null, 'deal_id' => null];
        $source = null;
        if (! empty($data['lead_id'])) {
            $source = Lead::where('organization_id', $org)->when($document?->lead_id === (int) $data['lead_id'], fn ($query) => $query->withTrashed())->findOrFail($data['lead_id']);
            $relations['lead_id'] = $source->id;
        }
        if (! empty($data['client_id'])) {
            $client = Client::where('organization_id', $org)->when($document?->client_id === (int) $data['client_id'], fn ($query) => $query->withTrashed())->findOrFail($data['client_id']);
            $relations['client_id'] = $client->id;
            $source ??= $client;
        }
        if (! empty($data['deal_id'])) {
            $deal = Deal::where('organization_id', $org)->when($document?->deal_id === (int) $data['deal_id'], fn ($query) => $query->withTrashed())->findOrFail($data['deal_id']);
            if ($relations['client_id'] && $deal->client_id !== $relations['client_id']) {
                throw ValidationException::withMessages(['deal_id' => 'Deal and Client do not match.']);
            } $relations['deal_id'] = $deal->id;
            $relations['client_id'] ??= $deal->client_id;
            $source ??= $deal->client;
        }
        $snapshot = $source ? [
            'customer_type' => filled($source->company ?? null) ? 'business' : 'individual',
            'contact_name' => trim(($source->first_name ?? '').' '.($source->last_name ?? '')),
            'company_name' => $source->company ?? null, 'email' => $source->email ?? null,
            'phone' => $source->phone ?? null, 'city' => $source->city ?? null,
            'state' => $source->state ?? null, 'country' => $source->country ?? null,
        ] : [];
        foreach (($data['customer_snapshot'] ?? []) as $key => $value) {
            $snapshot[$key] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        return [$relations, $snapshot, $source?->assigned_to ?? $request->user()->id];
    }

    private function calculate(Request $request, array $items): array
    {
        $profile = BillingProfile::where('organization_id', $request->user()->organization_id)->first();
        $tax = $profile?->tax_settings ?? [];

        return $this->calculator->calculate($items, (bool) ($request->input('tax_context.interstate', false)), (bool) ($tax['gst_enabled'] ?? true), (int) ($tax['rounding_scale'] ?? 2));
    }

    private function replaceItems(BillingDocument $document, array $inputs, array $calculated, Request $request, ?Collection $originalItems = null): void
    {
        foreach ($calculated as $i => $line) {
            $input = $inputs[$i];
            $catalogueId = $input['catalogue_item_id'] ?? null;
            $original = $originalItems?->get($input['id'] ?? null);
            $snapshot = null;
            if ($catalogueId && $original?->catalogue_item_id === (int) $catalogueId) {
                $snapshot = $original->catalogue_snapshot;
            } elseif ($catalogueId) {
                $catalogue = BillingCatalogueItem::where('organization_id', $request->user()->organization_id)->where('is_active', true)->findOrFail($catalogueId);
                $snapshot = $catalogue->only(['id', 'type', 'code', 'name', 'category', 'description', 'unit', 'hsn_sac', 'default_tax_rate', 'prices', 'is_recurring']);
            }
            $document->items()->create(array_merge($line, ['catalogue_item_id' => $catalogueId, 'hsn_sac' => $input['hsn_sac'] ?? null, 'unit' => $input['unit'] ?? null, 'discount_type' => $input['discount_type'] ?? null, 'discount_value' => $input['discount_value'] ?? 0, 'metadata' => $input['metadata'] ?? [], 'catalogue_snapshot' => $snapshot]));
        }
    }

    private function scoped(Request $request)
    {
        $q = BillingDocument::where('organization_id', $request->user()->organization_id);
        if ($request->user()->isEmployee()) {
            $q->where(fn ($x) => $x->where('assigned_to', $request->user()->id)->orWhere('created_by', $request->user()->id));
        }

        return $q;
    }

    private function require(Request $request, string $action): void
    {
        $profile = BillingProfile::where('organization_id', $request->user()->organization_id)->first();
        abort_unless($this->permissions->allows($request->user(), $profile, $action), 403);
    }

    private function assertVisible(Request $request, BillingDocument $d): void
    {
        abort_unless($d->organization_id === $request->user()->organization_id, 404);
        $this->require($request, 'view');
        if ($request->user()->isEmployee()) {
            abort_unless(in_array($request->user()->id, [$d->assigned_to, $d->created_by], true), 403);
        }
    }

    private function assertSubmittable(Request $request, BillingDocument $d): void
    {
        $this->assertVisible($request, $d);
        $this->require($request, 'edit_draft');
        abort_if($d->issued_at || ! in_array($d->status, ['draft', 'pending_approval'], true), 422, 'Only unissued drafts can be submitted.');
    }

    private function validateIssuanceSnapshots(BillingDocument $document, ?BillingProfile $profile): void
    {
        $errors = [];
        if (! $profile || (blank($profile->legal_name) && blank($profile->trade_name))) {
            $errors['issuer.name'] = 'Complete the Company Profile legal or trade name before issuing.';
        }
        if (! $profile || (blank($profile->registered_address) && blank($profile->billing_address))) {
            $errors['issuer.address'] = 'Complete the Company Profile billing or registered address before issuing.';
        }
        $customer = $document->customer_snapshot ?? [];
        if (blank($customer['contact_name'] ?? null) && blank($customer['company_name'] ?? null)) {
            $errors['customer.name'] = 'Complete the customer name or company before issuing.';
        }
        if (blank($customer['address'] ?? null) && blank($customer['address_line1'] ?? null) && blank($customer['city'] ?? null)) {
            $errors['customer.address'] = 'Complete the customer address or city before issuing.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
