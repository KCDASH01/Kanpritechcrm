<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PromotionRedemption;
use App\Models\Subscription;
use App\Models\SubscriptionAuditEvent;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionLifecycleService
{
    public function __construct(
        private readonly RazorpaySubscriptionGateway $gateway,
        private readonly SubscriptionPricingService $pricing,
        private readonly PromotionService $promotions,
        private readonly RazorpayPromotionAdapter $promotionAdapter,
    ) {}

    public function createPending(Organization $organization, User $owner, string $plan, string $cycle, string $currency): Subscription
    {
        $quote = $this->pricing->quote($plan, $cycle, $currency);

        return Subscription::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'plan' => $plan,
            'billing_cycle' => $cycle,
            'subscription_source' => 'internal',
            'status' => 'trialing',
            'provider_status' => 'created',
            'is_active' => false,
            'gateway' => 'razorpay',
            'amount' => $quote['amount'],
            'currency' => $quote['currency'],
        ]);
    }

    public function selectTrial(Organization $organization, User $actor, string $plan, string $cycle, string $currency, ?string $couponCode = null): Subscription
    {
        $active = $organization->activeSubscription();
        if ($active && $active->plan !== 'free') {
            throw ValidationException::withMessages(['subscription' => 'This organization already has a paid or trial subscription.']);
        }

        $pending = Subscription::query()
            ->where('organization_id', $organization->id)
            ->where('subscription_source', 'internal')
            ->where('status', 'trialing')
            ->where('is_active', false)
            ->latest('id')
            ->first();

        if ($pending?->gateway_subscription_id) {
            throw ValidationException::withMessages(['subscription' => 'Payment authorization has already started for the selected trial.']);
        }

        $quote = $this->pricing->quote($plan, $cycle, $currency);
        if ($pending) {
            $pending->update([
                'plan' => $plan, 'billing_cycle' => $cycle, 'currency' => $currency,
                'amount' => $quote['amount'], 'provider_status' => 'created',
            ]);

            return $this->attachPromotion($organization, $pending->fresh(), $couponCode);
        }

        return $this->attachPromotion($organization, $this->createPending($organization, $actor, $plan, $cycle, $currency), $couponCode);
    }

    public function startAuthorization(Organization $organization, User $actor): array
    {
        if (! config('services.razorpay.test_mode') && ! config('services.razorpay.live_subscriptions_enabled')) {
            throw ValidationException::withMessages(['payment' => 'Live recurring subscriptions are disabled pending deployment approval.']);
        }

        $subscription = $this->pendingFor($organization);
        if ($subscription->currency === 'USD' && ! config('services.razorpay.usd_recurring_enabled')) {
            throw ValidationException::withMessages(['currency' => 'USD recurring payments are disabled until Razorpay confirms merchant eligibility.']);
        }
        $quote = $this->pricing->quote($subscription->plan, $subscription->billing_cycle, $subscription->currency);
        $gatewayPlanId = $this->gateway->planId($subscription->plan, $subscription->billing_cycle, $subscription->currency);
        $redemption = $this->promotionRedemption($subscription);
        if ($redemption) {
            $this->promotions->revalidate($redemption);
        }

        if ($subscription->gateway_subscription_id && $subscription->provider_status === 'created') {
            return $this->authorizationResponse($subscription, $quote);
        }

        $payload = $this->promotionAdapter->applyToSubscriptionPayload([
            'plan_id' => $gatewayPlanId,
            'total_count' => max(1, (int) config("services.razorpay.subscription_total_count.{$subscription->billing_cycle}")),
            'quantity' => 1,
            'customer_notify' => false,
            'start_at' => $quote['first_billing_at']->timestamp,
            'expire_by' => now()->addMinutes(30)->timestamp,
            'notes' => [
                'organization_id' => (string) $organization->id,
                'local_subscription_id' => (string) $subscription->id,
                'environment' => config('services.razorpay.test_mode') ? 'test' : 'live',
            ],
        ], $redemption);
        $created = $this->gateway->createSubscription($payload);

        $subscription->update([
            'gateway_subscription_id' => $created['id'],
            'gateway_plan_id' => $gatewayPlanId,
            'provider_status' => $created['status'] ?? 'created',
            'trial_ends_at' => $this->gatewayDate($created['start_at'] ?? $quote['first_billing_at']->timestamp),
            'next_charge_at' => $this->gatewayDate($created['charge_at'] ?? $quote['first_billing_at']->timestamp),
            'external_metadata' => array_merge($subscription->external_metadata ?? [], [
                'authorization_expires_at' => $created['expire_by'] ?? null,
                'short_url' => $created['short_url'] ?? null,
            ]),
        ]);

        $this->audit($subscription, $actor, 'authorization_created', null, $subscription->fresh()->toArray());

        return $this->authorizationResponse($subscription->fresh(), $quote);
    }

    public function verifyAuthorization(
        Organization $organization,
        User $actor,
        string $gatewaySubscriptionId,
        string $paymentId,
        string $signature,
    ): Subscription {
        if (! $this->gateway->verifyCheckoutSignature($gatewaySubscriptionId, $paymentId, $signature)) {
            throw ValidationException::withMessages(['payment' => 'Payment-method authorization signature is invalid.']);
        }

        $remote = $this->gateway->fetchSubscription($gatewaySubscriptionId);
        if (! in_array($remote['status'] ?? null, ['authenticated', 'active'], true)) {
            throw ValidationException::withMessages(['payment' => 'Razorpay has not verified the recurring payment authorization.']);
        }
        $redemption = $this->promotionRedemptionByOrganization($organization, $gatewaySubscriptionId);
        if ($redemption && (! $redemption->gateway_offer_id
            || ! hash_equals($redemption->gateway_offer_id, (string) ($remote['offer_id'] ?? '')))) {
            throw ValidationException::withMessages(['coupon_code' => 'Razorpay did not confirm the reserved coupon offer.']);
        }

        return DB::transaction(function () use ($organization, $actor, $gatewaySubscriptionId, $paymentId, $remote) {
            $subscription = Subscription::query()
                ->where('organization_id', $organization->id)
                ->where('gateway_subscription_id', $gatewaySubscriptionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($subscription->gateway_plan_id !== ($remote['plan_id'] ?? null)) {
                throw ValidationException::withMessages(['payment' => 'The authorized Razorpay plan does not match the selected Growneq plan.']);
            }

            $before = $subscription->toArray();
            $trialTimestamp = $remote['start_at'] ?? $subscription->trial_ends_at?->timestamp;
            if (! $trialTimestamp) {
                throw ValidationException::withMessages(['payment' => 'Razorpay did not return a valid first billing date.']);
            }
            $trialEnd = $this->gatewayDate($trialTimestamp);
            $subscription->update([
                'provider_status' => $remote['status'],
                'gateway_customer_id' => $remote['customer_id'] ?? null,
                'authorized_at' => now(),
                'trial_started_at' => now(),
                'trial_ends_at' => $trialEnd,
                'next_charge_at' => $this->gatewayDate($remote['charge_at'] ?? $trialEnd->timestamp),
                'start_date' => now(),
                'end_date' => $trialEnd,
                'access_until' => $trialEnd,
                'status' => 'trialing',
                'is_active' => true,
            ]);
            Subscription::query()
                ->where('organization_id', $organization->id)
                ->where('subscription_source', 'internal')
                ->where('id', '!=', $subscription->id)
                ->where('plan', 'free')
                ->update(['is_active' => false, 'status' => 'cancelled']);

            SubscriptionTransaction::updateOrCreate(
                ['gateway' => 'razorpay', 'gateway_payment_id' => $paymentId],
                [
                    'organization_id' => $organization->id,
                    'subscription_id' => $subscription->id,
                    'type' => 'authorization',
                    'status' => 'authorized',
                    'amount_minor' => 0,
                    'currency' => $subscription->currency,
                    'occurred_at' => now(),
                ]
            );
            $this->audit($subscription, $actor, 'trial_activated', $before, $subscription->fresh()->toArray());

            return $subscription->fresh();
        });
    }

    public function cancel(Organization $organization, User $actor): Subscription
    {
        return DB::transaction(function () use ($organization, $actor) {
            $subscription = Subscription::query()
                ->where('organization_id', $organization->id)
                ->where('subscription_source', 'internal')
                ->where('is_active', true)
                ->latest('id')
                ->lockForUpdate()
                ->firstOrFail();

            if ($subscription->cancel_requested_at) {
                return $subscription;
            }

            $duringTrial = $subscription->status === 'trialing';
            $remote = $this->gateway->cancelSubscription($subscription->gateway_subscription_id, ! $duringTrial);
            $before = $subscription->toArray();
            $accessUntil = $duringTrial
                ? $subscription->trial_ends_at
                : ($subscription->current_period_end ?? $subscription->end_date);

            $subscription->update([
                'provider_status' => $remote['status'] ?? ($duringTrial ? 'cancelled' : $subscription->provider_status),
                'cancel_requested_at' => now(),
                'cancelled_at' => $duringTrial ? now() : null,
                'cancel_at_period_end' => ! $duringTrial,
                'access_until' => $accessUntil,
                'end_date' => $accessUntil,
            ]);
            $this->audit($subscription, $actor, $duringTrial ? 'trial_cancelled' : 'cancellation_scheduled', $before, $subscription->fresh()->toArray());

            return $subscription->fresh();
        });
    }

    private function pendingFor(Organization $organization): Subscription
    {
        $subscription = Subscription::query()
            ->where('organization_id', $organization->id)
            ->where('subscription_source', 'internal')
            ->where('is_active', false)
            ->where('status', 'trialing')
            ->latest('id')
            ->first();

        if (! $subscription) {
            throw ValidationException::withMessages(['subscription' => 'No pending trial was found for this organization.']);
        }

        return $subscription;
    }

    private function authorizationResponse(Subscription $subscription, array $quote): array
    {
        $response = [
            'subscription_id' => $subscription->gateway_subscription_id,
            'key' => $this->gateway->keyId(),
            'plan' => $subscription->plan,
            'billing_cycle' => $subscription->billing_cycle,
            'amount_minor' => $quote['amount_minor'],
            'amount' => $quote['amount'],
            'currency' => $subscription->currency,
            'tax_exclusive' => true,
            'first_billing_at' => $subscription->trial_ends_at?->toIso8601String() ?? $quote['first_billing_at']->toIso8601String(),
            'authorization_disclosure' => 'Razorpay or your bank may place a nominal authorization charge or temporary hold. Any applicable amount and refund timing are shown in Razorpay Checkout.',
        ];
        if ($redemption = $this->promotionRedemption($subscription)) {
            $response['promotion'] = data_get($redemption->metadata, 'quote');
        }

        return $response;
    }

    private function attachPromotion(Organization $organization, Subscription $subscription, ?string $couponCode): Subscription
    {
        if (! config('services.promotions.enabled', false)) {
            if ($couponCode && trim($couponCode) !== '') {
                throw ValidationException::withMessages(['coupon_code' => 'Coupons are not currently enabled.']);
            }

            return $subscription;
        }
        if (! $couponCode || trim($couponCode) === '') {
            $this->promotions->releasePendingFor($subscription, 'coupon_removed');
            $metadata = $subscription->external_metadata ?? [];
            unset($metadata['promotion_redemption_id'], $metadata['promotion_code']);
            $subscription->update(['external_metadata' => $metadata]);
            try {
                $redemption = $this->promotions->reserve($organization, $subscription->fresh(), null);
                $subscription->update(['external_metadata' => array_merge($metadata, [
                    'promotion_redemption_id' => $redemption->id,
                    'promotion_code' => null,
                ])]);
            } catch (ValidationException) {
                // No eligible automatic campaign; standard pricing remains unchanged.
            }

            return $subscription->fresh();
        }

        $this->promotions->releasePendingFor($subscription, 'coupon_replaced');
        $redemption = $this->promotions->reserve($organization, $subscription, $couponCode);
        $subscription->update([
            'external_metadata' => array_merge($subscription->external_metadata ?? [], [
                'promotion_redemption_id' => $redemption->id,
                'promotion_code' => $redemption->campaign()->value('code'),
            ]),
        ]);

        return $subscription->fresh();
    }

    private function promotionRedemption(Subscription $subscription): ?PromotionRedemption
    {
        $id = data_get($subscription->external_metadata, 'promotion_redemption_id');

        return $id ? PromotionRedemption::with('campaign')->find($id) : null;
    }

    private function promotionRedemptionByOrganization(Organization $organization, string $gatewaySubscriptionId): ?PromotionRedemption
    {
        $subscription = Subscription::where('organization_id', $organization->id)
            ->where('gateway_subscription_id', $gatewaySubscriptionId)
            ->first();

        return $subscription ? $this->promotionRedemption($subscription) : null;
    }

    private function audit(Subscription $subscription, ?User $actor, string $event, ?array $before, ?array $after): void
    {
        SubscriptionAuditEvent::create([
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'actor_user_id' => $actor?->id,
            'event_type' => $event,
            'before' => $before,
            'after' => $after,
            'created_at' => now(),
        ]);
    }

    private function gatewayDate(mixed $timestamp): Carbon
    {
        return Carbon::createFromTimestamp((int) $timestamp)->setTimezone(config('app.timezone'));
    }
}
