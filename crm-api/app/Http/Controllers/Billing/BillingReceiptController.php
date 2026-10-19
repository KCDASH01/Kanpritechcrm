<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingConsolidatedReceipt;
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
        $query = BillingConsolidatedReceipt::where('organization_id', $request->user()->organization_id)->with(['payments.payment', 'client']);
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
        $payment = DealPayment::where('organization_id', $request->user()->organization_id)->with(['deal' => fn ($q) => $q->withTrashed()->with('client')])->findOrFail($data['deal_payment_id']);
        $receipt = DB::transaction(function () use ($request, $payment) {
            if ($existing = BillingReceipt::where('organization_id', $payment->organization_id)->where('deal_payment_id', $payment->id)->first()) {
                return $existing;
            }
            $profile = BillingProfile::where('organization_id', $payment->organization_id)->firstOrFail();
            $receipt = BillingReceipt::create(['uuid' => (string) Str::uuid(), 'organization_id' => $payment->organization_id, 'client_id' => $payment->deal?->client_id, 'deal_payment_id' => $payment->id, 'receipt_number' => $this->numbers->nextNumber($payment->organization_id, 'payment_receipt', $request->user(), now()), 'issuer_snapshot' => $this->issuer($profile), 'customer_snapshot' => $this->customer($payment->deal?->client), 'payment_snapshot' => array_merge($payment->only(['id', 'amount', 'payment_date', 'payment_mode', 'txn_or_utr_number', 'notes']), ['currency' => $payment->deal?->currency, 'product_details' => $payment->deal?->title]), 'status' => 'issued', 'issued_at' => now(), 'issued_by' => $request->user()->id]);
            $this->pdf->storedReceipt($receipt);
            $this->audit->record($receipt->organization_id, $request->user(), 'receipt.issued', $receipt, null, ['payment_id' => $payment->id], $request);

            return $receipt;
        }, 3);

        return response()->json(['data' => $receipt], 201);
    }

    public function consolidatedStore(Request $request): JsonResponse
    {
        $this->require($request, 'generate_receipts');
        $data = $request->validate(['client_id' => ['required', 'integer'], 'payment_ids' => ['required', 'array', 'min:1'], 'payment_ids.*' => ['integer', 'distinct'], 'period_start' => ['nullable', 'date'], 'period_end' => ['nullable', 'date', 'after_or_equal:period_start']]);
        $client = Client::where('organization_id', $request->user()->organization_id)->findOrFail($data['client_id']);
        $payments = DealPayment::where('organization_id', $request->user()->organization_id)->whereIn('id', $data['payment_ids'])->with(['deal' => fn ($q) => $q->withTrashed()])->get();
        if ($payments->count() !== count($data['payment_ids']) || $payments->contains(fn ($p) => $p->deal?->client_id !== $client->id)) {
            throw ValidationException::withMessages(['payment_ids' => 'All payments must belong to this Client and organization.']);
        }
        $currencies = $payments->pluck('deal.currency')->filter()->unique();
        if ($currencies->count() > 1) {
            throw ValidationException::withMessages(['payment_ids' => 'Consolidated receipts require a single currency.']);
        }
        $receipt = DB::transaction(function () use ($request, $client, $payments, $data) {
            $profile = BillingProfile::where('organization_id', $client->organization_id)->firstOrFail();
            $receipt = BillingConsolidatedReceipt::create(['uuid' => (string) Str::uuid(), 'organization_id' => $client->organization_id, 'client_id' => $client->id, 'receipt_number' => $this->numbers->nextNumber($client->organization_id, 'consolidated_receipt', $request->user(), now()), 'period_start' => $data['period_start'] ?? null, 'period_end' => $data['period_end'] ?? null, 'represented_total' => $payments->sum('amount'), 'issuer_snapshot' => $this->issuer($profile), 'customer_snapshot' => $this->customer($client), 'status' => 'issued', 'issued_at' => now(), 'issued_by' => $request->user()->id]);
            foreach ($payments as $payment) {
                $receipt->payments()->create(['deal_payment_id' => $payment->id, 'amount_snapshot' => $payment->amount]);
            }
            $this->pdf->storedConsolidatedReceipt($receipt);
            $this->audit->record($receipt->organization_id, $request->user(), 'consolidated_receipt.issued', $receipt, null, ['payment_ids' => $payments->pluck('id')->all()], $request);

            return $receipt;
        }, 3);

        return response()->json(['data' => $receipt->load('payments.payment')], 201);
    }

    private function issuer(BillingProfile $p): array
    {
        return array_merge($p->only(['legal_name', 'trade_name', 'registered_address', 'billing_address', 'city', 'state', 'postal_code', 'country', 'contact_email', 'contact_phone', 'website', 'gstin', 'pan', 'logo_path', 'signature_path', 'default_currency', 'document_footer']), ['bank_account' => $p->bankAccounts()->where('is_active', true)->orderByDesc('is_default')->first()?->toArray()]);
    }

    private function customer(?Client $c): array
    {
        return $c ? ['contact_name' => $c->full_name, 'company_name' => $c->company, 'email' => $c->email, 'phone' => $c->phone, 'city' => $c->city, 'state' => $c->state, 'country' => $c->country] : [];
    }

    private function require(Request $request, string $action): void
    {
        $p = BillingProfile::where('organization_id', $request->user()->organization_id)->first();
        abort_unless($this->permissions->allows($request->user(), $p, $action), 403);
    }
}
