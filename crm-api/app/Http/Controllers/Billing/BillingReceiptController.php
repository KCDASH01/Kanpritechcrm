<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingConsolidatedReceipt;
use App\Models\BillingDocument;
use App\Models\BillingProfile;
use App\Models\BillingReceipt;
use App\Models\Client;
use App\Models\DealPayment;
use App\Services\Billing\BillingAuditService;
use App\Services\Billing\BillingNumberService;
use App\Services\Billing\BillingPdfService;
use App\Services\Billing\BillingPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingReceiptController extends Controller
{
    public function __construct(private readonly BillingNumberService $numbers, private readonly BillingPdfService $pdf, private readonly BillingPermissionService $permissions, private readonly BillingAuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->require($request, 'view');
        $query = BillingReceipt::where('organization_id', $request->user()->organization_id)->with('payment.deal');
        if ($request->user()->isEmployee()) {
            $query->whereHas('payment.deal', fn ($q) => $q->withTrashed()->where('assigned_to', $request->user()->id));
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
        if ($request->filled('deal_payment_id')) {
            $query->where('deal_payment_id', $request->integer('deal_payment_id'));
        }
        if ($request->filled('deal_id')) {
            $query->whereHas('payment', fn ($q) => $q->where('deal_id', $request->integer('deal_id')));
        }

        return response()->json($query->latest()->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function consolidatedIndex(Request $request): JsonResponse
    {
        $this->require($request, 'view');
        $query = BillingConsolidatedReceipt::where('organization_id', $request->user()->organization_id)->with(['payments.payment', 'client', 'document']);
        if ($request->user()->isEmployee()) {
            $query->whereHas('payments.payment.deal', fn ($q) => $q->withTrashed()->where('assigned_to', $request->user()->id));
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
        if ($request->filled('deal_id')) {
            $query->whereHas('payments.payment', fn ($q) => $q->where('deal_id', $request->integer('deal_id')));
        }

        return response()->json($query->latest()->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function store(Request $request): JsonResponse
    {
        $this->require($request, 'generate_receipts');
        $data = $request->validate(['deal_payment_id' => ['required', 'integer']]);
        $receipt = DB::transaction(function () use ($request, $data) {
            $payment = DealPayment::where('organization_id', $request->user()->organization_id)->lockForUpdate()->findOrFail($data['deal_payment_id']);
            $payment->load(['deal' => fn ($q) => $q->withTrashed()->with('client'), 'billingAllocations' => fn ($q) => $q->whereNull('reversed_at')->with('document.items')]);
            if ($existing = BillingReceipt::where('organization_id', $payment->organization_id)->where('deal_payment_id', $payment->id)->lockForUpdate()->first()) {
                return $existing;
            }
            $profile = BillingProfile::where('organization_id', $payment->organization_id)->firstOrFail();
            $this->validateIssuer($profile);
            $products = $payment->billingAllocations->flatMap(fn ($allocation) => $allocation->document?->items?->pluck('description') ?? collect())->filter()->unique()->values()->implode(', ');
            $receipt = BillingReceipt::create(['uuid' => (string) Str::uuid(), 'organization_id' => $payment->organization_id, 'client_id' => $payment->deal?->client_id, 'deal_payment_id' => $payment->id, 'receipt_number' => $this->numbers->nextNumber($payment->organization_id, 'payment_receipt', $request->user(), now()), 'issuer_snapshot' => $this->issuer($profile), 'customer_snapshot' => $this->customer($payment->deal?->client), 'payment_snapshot' => array_merge($payment->only(['id', 'amount', 'payment_date', 'payment_mode', 'txn_or_utr_number', 'notes']), ['currency' => $payment->deal?->currency ?: $profile->default_currency, 'product_details' => $products ?: $payment->deal?->title]), 'status' => 'issued', 'issued_at' => now(), 'issued_by' => $request->user()->id]);
            $this->pdf->storedReceipt($receipt);
            $this->audit->record($receipt->organization_id, $request->user(), 'receipt.issued', $receipt, null, ['payment_id' => $payment->id], $request);

            return $receipt;
        }, 3);

        return response()->json(['data' => $receipt], 201);
    }

    public function invoiceAllocations(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        $this->assertInvoice($request, $billingDocument);
        $rows = $billingDocument->allocations()->whereNull('reversed_at')->with([
            'payment' => fn ($q) => $q->with(['deal' => fn ($deal) => $deal->withTrashed()]),
        ])->get()->groupBy('deal_payment_id')->map(function ($allocations) use ($billingDocument) {
            $payment = $allocations->first()->payment;
            $receipt = BillingReceipt::where('organization_id', $billingDocument->organization_id)->where('deal_payment_id', $payment->id)->first();

            return [
                'deal_payment_id' => $payment->id,
                'payment_date' => $payment->payment_date?->toDateString(),
                'payment_method' => $payment->payment_mode,
                'reference' => $payment->txn_or_utr_number,
                'product_details' => $billingDocument->items->pluck('description')->filter()->implode(', '),
                'allocated_amount' => number_format((float) $allocations->sum('amount'), 2, '.', ''),
                'receipt_number' => $receipt?->receipt_number,
            ];
        })->values();
        $received = (float) $rows->sum('allocated_amount');

        return response()->json(['data' => [
            'invoice' => $billingDocument->loadMissing('items'), 'payments' => $rows,
            'total_billed' => $billingDocument->grand_total, 'total_received' => number_format($received, 2, '.', ''),
            'balance_due' => number_format(max(0, (float) $billingDocument->grand_total - $received), 2, '.', ''),
            'payment_status' => $received <= 0 ? 'unpaid' : ($received + .00001 >= (float) $billingDocument->grand_total ? 'paid' : 'partially_paid'),
        ]]);
    }

    public function consolidatedStore(Request $request): JsonResponse
    {
        $this->require($request, 'generate_receipts');
        $data = $request->validate(['billing_document_id' => ['required', 'integer']]);
        $document = BillingDocument::where('organization_id', $request->user()->organization_id)->with(['items', 'client'])->findOrFail($data['billing_document_id']);
        $this->assertInvoice($request, $document);
        $receipt = DB::transaction(function () use ($request, $document) {
            $locked = BillingDocument::whereKey($document->id)->lockForUpdate()->with(['items', 'client'])->firstOrFail();
            $allocations = $locked->allocations()->whereNull('reversed_at')->with(['payment' => fn ($q) => $q->with(['deal' => fn ($deal) => $deal->withTrashed()])])->get();
            if ($allocations->isEmpty()) {
                throw ValidationException::withMessages(['billing_document_id' => 'This invoice has no effective payment allocations.']);
            }
            $grouped = $allocations->groupBy('deal_payment_id');
            $profile = BillingProfile::where('organization_id', $locked->organization_id)->firstOrFail();
            $this->validateIssuer($profile);
            $represented = $grouped->sum(fn ($items) => (float) $items->sum('amount'));
            $existing = BillingConsolidatedReceipt::where('organization_id', $locked->organization_id)
                ->where('billing_document_id', $locked->id)->with('payments')->latest('id')->get()
                ->first(function (BillingConsolidatedReceipt $candidate) use ($grouped, $represented) {
                    if (abs((float) $candidate->represented_total - $represented) > .00001 || $candidate->payments->count() !== $grouped->count()) {
                        return false;
                    }

                    return $grouped->every(function ($items, $paymentId) use ($candidate) {
                        $snapshot = $candidate->payments->firstWhere('deal_payment_id', (int) $paymentId);

                        return $snapshot && abs((float) $snapshot->amount_snapshot - (float) $items->sum('amount')) < .00001;
                    });
                });
            if ($existing) {
                return $existing;
            }
            $customer = array_filter($locked->customer_snapshot ?? []) ?: $this->customer($locked->client);
            $invoiceSnapshot = ['id' => $locked->id, 'document_number' => $locked->document_number, 'currency' => $locked->currency, 'grand_total' => $locked->grand_total, 'payment_status' => $locked->payment_status, 'product_details' => $locked->items->pluck('description')->filter()->implode(', ')];
            $receipt = BillingConsolidatedReceipt::create(['uuid' => (string) Str::uuid(), 'organization_id' => $locked->organization_id, 'client_id' => $locked->client_id, 'billing_document_id' => $locked->id, 'receipt_number' => $this->numbers->nextNumber($locked->organization_id, 'consolidated_receipt', $request->user(), now()), 'period_start' => $grouped->min(fn ($items) => $items->first()->payment?->payment_date), 'period_end' => $grouped->max(fn ($items) => $items->first()->payment?->payment_date), 'represented_total' => number_format($represented, 2, '.', ''), 'issuer_snapshot' => $this->issuer($profile), 'customer_snapshot' => $customer, 'invoice_snapshot' => $invoiceSnapshot, 'status' => 'issued', 'issued_at' => now(), 'issued_by' => $request->user()->id]);
            foreach ($grouped as $items) {
                $payment = $items->first()->payment;
                $amount = (float) $items->sum('amount');
                $individual = BillingReceipt::where('organization_id', $locked->organization_id)->where('deal_payment_id', $payment->id)->first();
                $receipt->payments()->create(['deal_payment_id' => $payment->id, 'amount_snapshot' => number_format($amount, 2, '.', ''), 'payment_snapshot' => ['receipt_number' => $individual?->receipt_number, 'payment_date' => $payment->payment_date?->toDateString(), 'payment_method' => $payment->payment_mode, 'reference' => $payment->txn_or_utr_number, 'product_details' => $invoiceSnapshot['product_details'], 'currency' => $locked->currency]]);
            }
            $this->pdf->storedConsolidatedReceipt($receipt);
            $this->audit->record($receipt->organization_id, $request->user(), 'consolidated_receipt.issued', $receipt, null, ['billing_document_id' => $locked->id, 'payment_ids' => $grouped->keys()->all()], $request);

            return $receipt;
        }, 3);

        return response()->json(['data' => $receipt->load('payments.payment', 'document')], 201);
    }

    private function issuer(BillingProfile $p): array
    {
        return array_merge($p->only(['legal_name', 'trade_name', 'registered_address', 'billing_address', 'city', 'state', 'postal_code', 'country', 'contact_email', 'contact_phone', 'website', 'gstin', 'pan', 'logo_path', 'signature_path', 'default_currency', 'document_footer']), ['bank_account' => $p->bankAccounts()->where('is_active', true)->orderByDesc('is_default')->first()?->toArray()]);
    }

    private function customer(?Client $c): array
    {
        return $c ? ['customer_type' => filled($c->company) ? 'business' : 'individual', 'contact_name' => $c->full_name, 'company_name' => $c->company, 'email' => $c->email, 'phone' => $c->phone, 'city' => $c->city, 'state' => $c->state, 'country' => $c->country] : [];
    }

    private function require(Request $request, string $action): void
    {
        $p = BillingProfile::where('organization_id', $request->user()->organization_id)->first();
        abort_unless($this->permissions->allows($request->user(), $p, $action), 403);
    }

    private function assertInvoice(Request $request, BillingDocument $document): void
    {
        abort_unless($document->organization_id === $request->user()->organization_id, 404);
        $this->require($request, 'view');
        abort_unless($document->document_type === 'invoice' && $document->issued_at !== null && $document->voided_at === null, 422, 'Select an issued, active Invoice.');
        if ($request->user()->isEmployee()) {
            abort_unless(in_array($request->user()->id, [$document->assigned_to, $document->created_by], true), 403);
        }
    }

    private function validateIssuer(BillingProfile $profile): void
    {
        if ((blank($profile->legal_name) && blank($profile->trade_name)) || (blank($profile->registered_address) && blank($profile->billing_address))) {
            throw ValidationException::withMessages(['issuer' => 'Complete the Billing Company Profile name and address before issuing receipts.']);
        }
    }
}
