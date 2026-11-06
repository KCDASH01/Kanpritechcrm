<?php

namespace App\Services;

use App\Models\PromotionAuditEvent;
use App\Models\PromotionRedemption;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PromotionReconciliationService
{
    public function paymentSucceeded(Subscription $subscription, array $remoteSubscription, array $payment, ?array $invoice = null): void
    {
        if (! $this->available()) {
            return;
        }
        $redemptionId = data_get($subscription->external_metadata, 'promotion_redemption_id');
        if (! $redemptionId || empty($payment['id'])) {
            return;
        }

        DB::transaction(function () use ($remoteSubscription, $payment, $invoice, $redemptionId) {
            $redemption = PromotionRedemption::whereKey($redemptionId)->lockForUpdate()->first();
            if (! $redemption || in_array($redemption->status, ['released', 'refunded', 'cancelled'], true)) {
                return;
            }
            if ($redemption->gateway_payment_id === $payment['id']) {
                return;
            }
            if (DB::table('promotion_redemption_applications')->where('gateway_payment_id', $payment['id'])->exists()) {
                return;
            }
            if (! in_array($payment['status'] ?? null, ['captured', 'paid'], true)) {
                return;
            }
            $remoteOfferId = (string) ($remoteSubscription['offer_id'] ?? '');
            if (! $redemption->gateway_offer_id || ! hash_equals($redemption->gateway_offer_id, $remoteOfferId)) {
                $redemption->update([
                    'status' => 'failed',
                    'metadata' => array_merge($redemption->metadata ?? [], ['failure' => 'Gateway offer did not match the reserved promotion.']),
                ]);

                return;
            }

            $paidMinor = max(0, (int) ($payment['amount'] ?? 0));
            $invoiceDiscount = data_get($invoice, 'discount_amount') ?? data_get($invoice, 'discount') ?? data_get($invoice, 'offer_amount');
            $actualDiscount = is_numeric($invoiceDiscount)
                ? max(0, (int) $invoiceDiscount)
                : ($paidMinor <= $redemption->original_amount_minor ? $redemption->original_amount_minor - $paidMinor : null);
            $cycles = $redemption->successful_cycles + 1;
            $campaign = $redemption->campaign()->first();
            $exhausted = $campaign->duration === 'first_cycle'
                || ($campaign->duration === 'limited_cycles' && $cycles >= (int) $campaign->discount_cycles);
            $redemption->update([
                'status' => $exhausted ? 'exhausted' : 'applied',
                'successful_cycles' => $cycles,
                'actual_discount_minor' => $actualDiscount,
                'gateway_payment_id' => $payment['id'],
                'gateway_invoice_id' => $payment['invoice_id'] ?? null,
                'applied_at' => $redemption->applied_at ?? now(),
                'metadata' => array_merge($redemption->metadata ?? [], [
                    'last_payment_amount_minor' => $paidMinor,
                    'actual_discount_source' => is_numeric($invoiceDiscount) ? 'gateway_invoice' : ($actualDiscount !== null ? 'gateway_payment' : 'unavailable'),
                ]),
            ]);
            DB::table('promotion_redemption_applications')->insert([
                'promotion_redemption_id' => $redemption->id,
                'cycle_number' => $cycles,
                'actual_discount_minor' => $actualDiscount,
                'gateway_payment_id' => $payment['id'],
                'gateway_invoice_id' => $payment['invoice_id'] ?? null,
                'status' => 'applied',
                'applied_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            PromotionAuditEvent::create([
                'promotion_campaign_id' => $redemption->promotion_campaign_id,
                'organization_id' => $redemption->organization_id,
                'event_type' => 'payment_discount_reconciled',
                'metadata' => ['redemption_id' => $redemption->id, 'gateway_payment_id' => $payment['id']],
                'created_at' => now(),
            ]);
        });
    }

    public function paymentRefunded(array $payment): void
    {
        if (! $this->available()) {
            return;
        }
        if (empty($payment['id'])) {
            return;
        }
        DB::transaction(function () use ($payment) {
            $application = DB::table('promotion_redemption_applications')->where('gateway_payment_id', $payment['id'])->lockForUpdate()->first();
            if (! $application || $application->status === 'refunded') {
                return;
            }
            DB::table('promotion_redemption_applications')->where('id', $application->id)->update(['status' => 'refunded', 'refunded_at' => now(), 'updated_at' => now()]);
            $remaining = DB::table('promotion_redemption_applications')->where('promotion_redemption_id', $application->promotion_redemption_id)->where('status', 'applied')->count();
            if ($remaining === 0) {
                PromotionRedemption::whereKey($application->promotion_redemption_id)->update(['status' => 'refunded']);
            }
        });
    }

    public function releaseUnapplied(Subscription $subscription, string $status): void
    {
        if (! $this->available()) {
            return;
        }
        PromotionRedemption::where('subscription_id', $subscription->id)
            ->where('status', 'reserved')
            ->update(['status' => $status, 'released_at' => now()]);
    }

    private function available(): bool
    {
        return (bool) config('services.promotions.enabled', false)
            && Schema::hasTable('promotion_redemptions');
    }
}
