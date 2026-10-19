<?php

namespace App\Services\Billing;

use App\Models\BillingDocument;
use App\Models\BillingPaymentAllocation;
use App\Models\DealPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentAllocationService
{
    public function allocate(BillingDocument $document, DealPayment $payment, string $amount, User $actor): BillingPaymentAllocation
    {
        return DB::transaction(function () use ($document, $payment, $amount, $actor): BillingPaymentAllocation {
            $document = BillingDocument::query()->lockForUpdate()->findOrFail($document->id);
            $payment = DealPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($document->organization_id !== $actor->organization_id || $payment->organization_id !== $actor->organization_id) {
                throw ValidationException::withMessages(['allocation' => 'Cross-organization allocations are forbidden.']);
            }
            if ($document->document_type !== 'invoice' || $document->issued_at === null) {
                throw ValidationException::withMessages(['document' => 'Payments can only be allocated to issued invoices.']);
            }
            if ($document->deal_id !== null && $document->deal_id !== $payment->deal_id) {
                throw ValidationException::withMessages(['payment' => 'The payment belongs to another deal.']);
            }
            $paymentDeal = $payment->deal;
            if ($paymentDeal !== null && $document->currency !== $paymentDeal->currency) {
                throw ValidationException::withMessages(['payment' => 'Payment and invoice currencies must match.']);
            }
            if ($document->client_id !== null && $paymentDeal?->client_id !== null && $document->client_id !== $paymentDeal->client_id) {
                throw ValidationException::withMessages(['payment' => 'Payment and invoice must belong to the same client.']);
            }
            $cents = (int) round(((float) $amount) * 100);
            if ($cents <= 0) {
                throw ValidationException::withMessages(['amount' => 'Allocation amount must be greater than zero.']);
            }
            $paymentAllocated = (float) BillingPaymentAllocation::where('deal_payment_id', $payment->id)->whereNull('reversed_at')->sum('amount');
            $invoiceAllocated = (float) BillingPaymentAllocation::where('billing_document_id', $document->id)->whereNull('reversed_at')->sum('amount');
            if (($paymentAllocated + ($cents / 100)) > ((float) $payment->amount + 0.00001)) {
                throw ValidationException::withMessages(['amount' => 'Allocation exceeds the unallocated payment amount.']);
            }
            if (($invoiceAllocated + ($cents / 100)) > ((float) $document->grand_total + 0.00001)) {
                throw ValidationException::withMessages(['amount' => 'Allocation exceeds the invoice balance.']);
            }

            return BillingPaymentAllocation::create([
                'organization_id' => $actor->organization_id, 'billing_document_id' => $document->id,
                'deal_payment_id' => $payment->id, 'amount' => number_format($cents / 100, 2, '.', ''),
                'allocated_on' => now()->toDateString(), 'allocated_by' => $actor->id,
            ]);
        }, 3);
    }
}
