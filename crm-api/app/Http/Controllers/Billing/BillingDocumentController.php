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

        return response()->json($query->latest()->paginate(min(100, max(1, $request->integer('per_page', 20)))));
    }

    public function show(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        $this->assertVisible($request, $billingDocument);

        return response()->json(['data' => $billingDocument->load(['items.catalogueItem', 'client', 'deal', 'lead', 'sourceDocument', 'allocations.payment'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->require($request, 'create_draft');
        $data = $this->validateDocument($request);
        if (! empty($data['idempotency_key']) && ($existing = BillingDocument::where('organization_id', $request->user()->organization_id)->where('idempotency_key', $data['idempotency_key'])->first())) {
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
                'template' => $data['template'] ?? 'classic', 'due_date' => $data['due_date'] ?? null,
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
        $this->assertEditable($request, $billingDocument);
        $data = $this->validateDocument($request, $billingDocument->document_type);
        DB::transaction(function () use ($request, $data, $billingDocument) {
            [$relations, $snapshot, $assigned] = $this->resolveParties($request, $data);
            $calculation = $this->calculate($request, $data['items']);
            $before = $billingDocument->toArray();
            $billingDocument->update(array_merge($relations, $calculation['totals'], ['currency' => strtoupper($data['currency']), 'customer_snapshot' => $snapshot, 'assigned_to' => $assigned, 'tax_context' => $data['tax_context'] ?? [], 'template' => $data['template'] ?? $billingDocument->template, 'due_date' => $data['due_date'] ?? null, 'terms' => $data['terms'] ?? null, 'notes' => $data['notes'] ?? null, 'footer' => $data['footer'] ?? null]));
            $billingDocument->items()->delete();
            $this->replaceItems($billingDocument, $data['items'], $calculation['items'], $request);
            $this->audit->record($billingDocument->organization_id, $request->user(), 'document.updated', $billingDocument, $before, $billingDocument->fresh()->toArray(), $request);
        });

        return response()->json(['data' => $billingDocument->fresh()->load('items')]);
    }

    public function submit(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        $this->assertEditable($request, $billingDocument);
        $profile = BillingProfile::where('organization_id', $billingDocument->organization_id)->first();
        $status = $profile?->approval_required ? 'pending_approval' : 'approved';
        $billingDocument->update(['status' => $status, 'submitted_at' => now(), 'approved_at' => $status === 'approved' ? now() : null, 'approved_by' => $status === 'approved' ? $request->user()->id : null]);
        $this->audit->record($billingDocument->organization_id, $request->user(), 'document.submitted', $billingDocument, null, ['status' => $status], $request);

        return response()->json(['data' => $billingDocument->fresh()]);
    }

    public function approve(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        $this->assertVisible($request, $billingDocument);
        $this->require($request, 'issue');
        abort_unless($billingDocument->status === 'pending_approval', 422, 'Only pending documents can be approved.');
        $billingDocument->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $request->user()->id]);
        $this->audit->record($billingDocument->organization_id, $request->user(), 'document.approved', $billingDocument, null, null, $request);

        return response()->json(['data' => $billingDocument->fresh()]);
    }

    public function issue(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        $this->assertVisible($request, $billingDocument);
        $this->require($request, 'issue');
        $profile = BillingProfile::where('organization_id', $billingDocument->organization_id)->first();
        if ($profile?->approval_required && $billingDocument->status !== 'approved') {
            throw ValidationException::withMessages(['status' => 'This document requires approval before issuance.']);
        }
        if (! $billingDocument->items()->exists() || (float) $billingDocument->grand_total < 0) {
            throw ValidationException::withMessages(['items' => 'At least one valid line item is required.']);
        }
        $this->validateIssuanceSnapshots($billingDocument, $profile);
        $path = null;
        try {
            $issued = DB::transaction(function () use ($request, $billingDocument, &$path) {
                $issued = $this->numbers->issue($billingDocument, $request->user(), now());
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
            $invoice = $source->replicate(['uuid', 'document_number', 'issued_at', 'issued_by', 'pdf_path', 'pdf_sha256', 'submitted_at', 'approved_at', 'approved_by', 'voided_at', 'voided_by', 'void_reason', 'idempotency_key']);
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
        $this->assertVisible($request, $billingDocument);
        $this->require($request, 'create_draft');
        abort_unless($billingDocument->document_type === 'proforma', 422, 'Only Proforma Invoices can be linked after Lead conversion.');
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
        $billingDocument->update(['client_id' => $client->id, 'deal_id' => $deal->id, 'assigned_to' => $deal->assigned_to]);
        $this->audit->record($billingDocument->organization_id, $request->user(), 'document.commercial_linked', $billingDocument, null, ['client_id' => $client->id, 'deal_id' => $deal->id], $request);

        return response()->json(['data' => $billingDocument->fresh()]);
    }

    public function void(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        $this->assertVisible($request, $billingDocument);
        $this->require($request, 'void');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        abort_if($billingDocument->voided_at, 422, 'Document already voided.');
        $billingDocument->forceFill(['status' => 'void', 'voided_at' => now(), 'voided_by' => $request->user()->id, 'void_reason' => $data['reason']])->save();
        $this->audit->record($billingDocument->organization_id, $request->user(), 'document.voided', $billingDocument, null, ['reason' => $data['reason']], $request);

        return response()->json(['data' => $billingDocument->fresh()]);
    }

    private function validateDocument(Request $request, ?string $forcedType = null): array
    {
        $data = $request->validate([
            'document_type' => [$forcedType ? 'sometimes' : 'required', Rule::in(['proforma', 'invoice'])], 'lead_id' => ['nullable', 'integer'], 'client_id' => ['nullable', 'integer'], 'deal_id' => ['nullable', 'integer'],
            'currency' => ['required', Rule::in(['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD'])], 'due_date' => ['nullable', 'date'], 'template' => ['nullable', Rule::in(BillingPdfService::TEMPLATES)],
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
            'items' => ['required', 'array', 'min:1'], 'items.*.catalogue_item_id' => ['nullable', 'integer'], 'items.*.description' => ['required', 'string', 'max:255'],
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

    private function resolveParties(Request $request, array $data): array
    {
        $org = $request->user()->organization_id;
        $relations = ['lead_id' => null, 'client_id' => null, 'deal_id' => null];
        $source = null;
        if (! empty($data['lead_id'])) {
            $source = Lead::where('organization_id', $org)->findOrFail($data['lead_id']);
            $relations['lead_id'] = $source->id;
        }
        if (! empty($data['client_id'])) {
            $client = Client::where('organization_id', $org)->findOrFail($data['client_id']);
            $relations['client_id'] = $client->id;
            $source ??= $client;
        }
        if (! empty($data['deal_id'])) {
            $deal = Deal::where('organization_id', $org)->findOrFail($data['deal_id']);
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

    private function replaceItems(BillingDocument $document, array $inputs, array $calculated, Request $request): void
    {
        foreach ($calculated as $i => $line) {
            $input = $inputs[$i];
            $catalogue = null;
            if (! empty($input['catalogue_item_id'])) {
                $catalogue = BillingCatalogueItem::where('organization_id', $request->user()->organization_id)->where('is_active', true)->findOrFail($input['catalogue_item_id']);
            }
            $document->items()->create(array_merge($line, ['catalogue_item_id' => $catalogue?->id, 'hsn_sac' => $input['hsn_sac'] ?? null, 'unit' => $input['unit'] ?? null, 'discount_type' => $input['discount_type'] ?? null, 'discount_value' => $input['discount_value'] ?? 0, 'metadata' => $input['metadata'] ?? [], 'catalogue_snapshot' => $catalogue?->only(['id', 'type', 'code', 'name', 'category', 'description', 'unit', 'hsn_sac', 'default_tax_rate', 'prices', 'is_recurring'])]));
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

    private function assertEditable(Request $request, BillingDocument $d): void
    {
        $this->assertVisible($request, $d);
        $this->require($request, 'edit_draft');
        abort_if($d->issued_at || ! in_array($d->status, ['draft', 'pending_approval'], true), 422, 'Only unissued drafts can be edited.');
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
