<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PromotionAuditEvent;
use App\Models\PromotionCampaign;
use App\Models\PromotionRedemption;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPrice;
use App\Models\SubscriptionTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PromotionService
{
    public function quote(
        Organization $organization,
        string $plan,
        string $cycle,
        string $currency,
        int $amountMinor,
        ?string $code,
        array $context = [],
    ): array {
        $this->ensureEnabled();
        $currency = strtoupper($currency);
        $campaign = $this->findCampaign($code);
        $planPrice = $this->planPrice($plan, $cycle, $currency, $amountMinor);
        $this->assertEligible($campaign, $organization, $planPrice, $currency, $amountMinor, $context);
        $discount = $this->discountMinor($campaign, $amountMinor);

        return [
            'campaign_id' => $campaign->id,
            'code' => $campaign->code,
            'name' => $campaign->name,
            'discount_type' => $campaign->discount_type,
            'percentage' => $campaign->discount_type === 'percentage'
                ? $campaign->percentage_basis_points / 100
                : null,
            'original_amount_minor' => $amountMinor,
            'discount_minor' => $discount,
            'first_billing_amount_minor' => $amountMinor - $discount,
            'regular_renewal_amount_minor' => $amountMinor,
            'currency' => $currency,
            'duration' => $campaign->duration,
            'discount_cycles' => $campaign->discount_cycles,
            'stackable' => $campaign->stackable,
            'gateway_supported' => $this->gatewayOfferId($campaign, $planPrice) !== null,
        ];
    }

    public function reserve(
        Organization $organization,
        Subscription $subscription,
        ?string $code,
        array $context = [],
    ): PromotionRedemption {
        return DB::transaction(function () use ($organization, $subscription, $code, $context) {
            $campaign = PromotionCampaign::query()
                ->when($code && trim($code) !== '',
                    fn ($query) => $query->whereRaw('UPPER(code) = ?', [Str::upper(trim($code))]),
                    fn ($query) => $query->where('kind', 'automatic')->where('state', 'active')->orderBy('priority'))
                ->lockForUpdate()->first();
            if (! $campaign) {
                throw ValidationException::withMessages(['coupon_code' => 'This coupon code is invalid.']);
            }

            $amountMinor = (int) round(((float) $subscription->amount) * 100);
            $quote = $this->quote(
                $organization,
                $subscription->plan,
                (string) $subscription->billing_cycle,
                $subscription->currency,
                $amountMinor,
                $code,
                $context,
            );

            $idempotencyKey = "subscription:{$subscription->id}:campaign:{$campaign->id}";
            $existing = PromotionRedemption::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing && in_array($existing->status, ['reserved', 'applied', 'exhausted'], true)) {
                return $existing;
            }

            $totalUsed = PromotionRedemption::where('promotion_campaign_id', $campaign->id)
                ->whereIn('status', ['reserved', 'applied', 'exhausted'])
                ->count();
            if ($campaign->total_redemption_limit !== null && $totalUsed >= $campaign->total_redemption_limit) {
                throw ValidationException::withMessages(['coupon_code' => 'This coupon has reached its redemption limit.']);
            }

            $customerUsed = PromotionRedemption::where('promotion_campaign_id', $campaign->id)
                ->where('organization_id', $organization->id)
                ->whereIn('status', ['reserved', 'applied', 'exhausted'])
                ->count();
            if ($customerUsed >= $campaign->per_customer_limit) {
                throw ValidationException::withMessages(['coupon_code' => 'This coupon has already been used by your organization.']);
            }

            $planPrice = $this->planPrice($subscription->plan, (string) $subscription->billing_cycle, $subscription->currency, $amountMinor);
            $redemption = PromotionRedemption::updateOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'reservation_token' => (string) Str::uuid(),
                    'promotion_campaign_id' => $campaign->id,
                    'organization_id' => $organization->id,
                    'subscription_id' => $subscription->id,
                    'original_amount_minor' => $amountMinor,
                    'expected_discount_minor' => $quote['discount_minor'],
                    'currency' => strtoupper($subscription->currency),
                    'status' => 'reserved',
                    'gateway' => 'razorpay',
                    'gateway_offer_id' => $this->gatewayOfferId($campaign, $planPrice),
                    'reserved_at' => now(),
                    'expires_at' => now()->addMinutes(max(5, (int) config('services.promotions.reservation_minutes', 30))),
                    'metadata' => ['quote' => $quote],
                ]
            );
            $this->audit($campaign, $organization, 'redemption_reserved', ['redemption_id' => $redemption->id]);

            return $redemption;
        });
    }

    public function releasePendingFor(Subscription $subscription, string $reason): void
    {
        PromotionRedemption::where('subscription_id', $subscription->id)
            ->where('status', 'reserved')
            ->get()
            ->each(function (PromotionRedemption $redemption) use ($reason) {
                $redemption->update([
                    'status' => 'released',
                    'released_at' => now(),
                    'metadata' => array_merge($redemption->metadata ?? [], ['release_reason' => $reason]),
                ]);
            });
    }

    public function revalidate(PromotionRedemption $redemption): array
    {
        if ($redemption->status !== 'reserved' || ($redemption->expires_at && $redemption->expires_at->isPast())) {
            throw ValidationException::withMessages(['coupon_code' => 'The coupon reservation has expired. Please apply it again.']);
        }
        $subscription = $redemption->subscription()->firstOrFail();
        $organization = $subscription->organization()->firstOrFail();

        $quote = $this->quote(
            $organization,
            $subscription->plan,
            (string) $subscription->billing_cycle,
            $subscription->currency,
            $redemption->original_amount_minor,
            (string) $redemption->campaign()->value('code'),
        );

        if ((int) $quote['campaign_id'] !== (int) $redemption->promotion_campaign_id) {
            throw ValidationException::withMessages([
                'coupon_code' => 'The reserved promotion is no longer available. Please apply it again.',
            ]);
        }

        return $quote;
    }

    private function findCampaign(?string $code): PromotionCampaign
    {
        if (! $code || trim($code) === '') {
            $campaign = PromotionCampaign::query()
                ->where('kind', 'automatic')
                ->where('state', 'active')
                ->orderBy('priority')
                ->first();
        } else {
            $campaign = PromotionCampaign::query()
                ->whereRaw('UPPER(code) = ?', [Str::upper(trim($code))])
                ->first();
        }
        if (! $campaign) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon code is invalid.']);
        }

        return $campaign;
    }

    private function assertEligible(
        PromotionCampaign $campaign,
        Organization $organization,
        SubscriptionPlanPrice $planPrice,
        string $currency,
        int $amountMinor,
        array $context,
    ): void {
        if ($campaign->state !== 'active' || $campaign->archived_at) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon is not active.']);
        }
        if (($campaign->starts_at && now()->lt($campaign->starts_at)) || ($campaign->ends_at && now()->gt($campaign->ends_at))) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon is outside its validity period.']);
        }
        if (! in_array($currency, $campaign->eligible_currencies ?? [], true)) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon is not available in the selected currency.']);
        }
        if ($amountMinor < $campaign->minimum_eligible_minor) {
            throw ValidationException::withMessages(['coupon_code' => 'The selected plan does not meet this coupon minimum.']);
        }
        if (! $campaign->planPrices()->whereKey($planPrice->id)->exists()) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon is not valid for the selected plan.']);
        }
        if ($campaign->audience === 'selected_customers'
            && ! $campaign->customerEligibilities()->where('organization_id', $organization->id)->exists()) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon is not assigned to your organization.']);
        }
        if ($campaign->audience === 'new_customers' && SubscriptionTransaction::query()
            ->where('organization_id', $organization->id)
            ->where('type', 'subscription_charge')
            ->whereIn('status', ['captured', 'paid'])
            ->exists()) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon is available to new customers only.']);
        }
        if ($campaign->audience === 'referral'
            && ! hash_equals((string) data_get($campaign->rules, 'referral_code', ''), (string) ($context['referral_code'] ?? ''))) {
            throw ValidationException::withMessages(['coupon_code' => 'A valid referral is required for this coupon.']);
        }
        if ($campaign->audience === 'loyalty') {
            $required = max(1, (int) data_get($campaign->rules, 'minimum_successful_payments', 1));
            $paid = SubscriptionTransaction::where('organization_id', $organization->id)
                ->where('type', 'subscription_charge')->whereIn('status', ['captured', 'paid'])->count();
            if ($paid < $required) {
                throw ValidationException::withMessages(['coupon_code' => 'This loyalty coupon is not yet available.']);
            }
        }
    }

    private function discountMinor(PromotionCampaign $campaign, int $amountMinor): int
    {
        $discount = $campaign->discount_type === 'percentage'
            ? intdiv($amountMinor * min(10000, max(0, $campaign->percentage_basis_points)), 10000)
            : min($amountMinor, max(0, $campaign->fixed_amount_minor));
        if ($campaign->maximum_discount_minor !== null) {
            $discount = min($discount, $campaign->maximum_discount_minor);
        }

        return min($amountMinor, max(0, $discount));
    }

    private function planPrice(string $plan, string $cycle, string $currency, int $amountMinor): SubscriptionPlanPrice
    {
        $price = SubscriptionPlanPrice::query()
            ->where(compact('plan'))
            ->where('billing_cycle', $cycle)
            ->where('currency', strtoupper($currency))
            ->where('amount_minor', $amountMinor)
            ->where('is_active', true)
            ->latest('effective_from')
            ->first();
        if (! $price) {
            throw ValidationException::withMessages(['coupon_code' => 'No eligible subscription price was found.']);
        }

        return $price;
    }

    private function gatewayOfferId(PromotionCampaign $campaign, SubscriptionPlanPrice $price): ?string
    {
        $row = $campaign->planPrices()->whereKey($price->id)->first();
        $offer = trim((string) $row?->pivot?->gateway_offer_id);

        return $offer !== '' ? $offer : null;
    }

    private function ensureEnabled(): void
    {
        if (! config('services.promotions.enabled', false)) {
            throw ValidationException::withMessages(['coupon_code' => 'Coupons are not currently enabled.']);
        }
    }

    private function audit(PromotionCampaign $campaign, Organization $organization, string $event, array $metadata): void
    {
        PromotionAuditEvent::create([
            'promotion_campaign_id' => $campaign->id,
            'organization_id' => $organization->id,
            'event_type' => $event,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
