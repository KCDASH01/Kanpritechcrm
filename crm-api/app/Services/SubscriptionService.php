<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    /**
     * Give the organization a free subscription (called on registration).
     */
    public function assignFree(Organization $org, User $user): Subscription
    {
        return Subscription::create([
            'organization_id'     => $org->id,
            'user_id'             => $user->id,
            'plan'                => 'free',
            'subscription_source' => 'internal',
            'start_date'          => now(),
            'end_date'            => null,
            'is_active'           => true,
            'status'              => 'active',
        ]);
    }

    /**
     * Upgrade (or downgrade) an organization's internal subscription.
     *
     * @param  Organization  $org
     * @param  string        $plan        'free' | 'business'
     * @param  string|null   $gateway     e.g. 'razorpay'
     * @param  string|null   $gatewayId   Gateway subscription / payment ID
     * @param  float|null    $amount
     * @param  string        $currency
     * @param  Carbon|null   $startDate
     * @param  Carbon|null   $endDate
     */
    public function changePlan(
        Organization $org,
        string $plan,
        ?string $gateway = null,
        ?string $gatewayId = null,
        ?float $amount = null,
        string $currency = 'INR',
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): Subscription {
        // Carry over purchased extra seats to the new plan.
        // Reset to 0 only when downgrading to free (seats have no value on free plan).
        $existingSub    = Subscription::where('organization_id', $org->id)
                            ->where('is_active', true)
                            ->first();
        $carryOverSeats = ($plan !== 'free')
                            ? (int) ($existingSub?->extra_members_purchased ?? 0)
                            : 0;

        // Deactivate existing internal subscriptions
        Subscription::where('organization_id', $org->id)
            ->where('subscription_source', 'internal')
            ->update(['is_active' => false, 'status' => 'cancelled']);

        return Subscription::create([
            'organization_id'        => $org->id,
            'user_id'                => $org->owner?->id ?? $org->users()->value('id'),
            'plan'                   => $plan,
            'subscription_source'    => 'internal',
            'start_date'             => $startDate ?? now(),
            'end_date'               => $endDate,
            'is_active'              => true,
            'status'                 => 'active',
            'gateway'                => $gateway,
            'gateway_subscription_id'=> $gatewayId,
            'amount'                 => $amount,
            'currency'               => $currency,
            'extra_members_purchased'=> $carryOverSeats,
        ]);
    }

    /**
     * Find every active Business subscription whose end_date has passed
     * and downgrade it to a Free plan automatically.
     *
     * Handles both internal and external (SSO-synced) subscriptions.
     * Returns the number of organizations downgraded.
     */
    public function downgradeExpired(): int
    {
        // Collect distinct organizations that have an expired active paid (Business or Enterprise) sub
        $orgIds = Subscription::where('is_active', true)
            ->whereIn('plan', ['business', 'enterprise'])
            ->whereNotNull('end_date')
            ->where('end_date', '<', now())
            ->distinct()
            ->pluck('organization_id');

        if ($orgIds->isEmpty()) {
            return 0;
        }

        $count = 0;

        foreach ($orgIds as $orgId) {
            $org = Organization::with('owner')->find($orgId);
            if (! $org) {
                continue;
            }

            // Mark ALL active paid subscriptions for this org as expired
            // (catches both internal and external sources, both Business and Enterprise)
            Subscription::where('organization_id', $org->id)
                ->where('is_active', true)
                ->whereIn('plan', ['business', 'enterprise'])
                ->update(['is_active' => false, 'status' => 'expired']);

            // Assign a new internal Free subscription
            $owner = $org->owner ?? $org->users()->first();
            if (! $owner) {
                Log::warning("ExpireSubscriptions: no owner found for org #{$org->id} ({$org->name}), skipping.");
                continue;
            }

            $this->assignFree($org, $owner);

            Log::info("ExpireSubscriptions: org #{$org->id} ({$org->name}) downgraded to free.");
            $count++;
        }

        return $count;
    }

    /**
     * Cancel the active internal subscription → downgrade to free.
     */
    public function cancel(Organization $org, User $user): Subscription
    {
        Subscription::where('organization_id', $org->id)
            ->where('subscription_source', 'internal')
            ->where('is_active', true)
            ->update(['is_active' => false, 'status' => 'cancelled']);

        return $this->assignFree($org, $user);
    }

    /**
     * Create the default pipeline for a newly registered organization.
     */
    public function createDefaultPipeline(Organization $org): Pipeline
    {
        $pipeline = Pipeline::create([
            'organization_id' => $org->id,
            'name'            => 'Sales Pipeline',
            'description'     => 'Default sales pipeline',
            'is_default'      => true,
            'sort_order'      => 0,
        ]);

        $stages = [
            ['name' => 'New',         'color' => '#6366f1', 'sort_order' => 0, 'probability' => 10,  'is_won' => false, 'is_lost' => false],
            ['name' => 'Contacted',   'color' => '#3b82f6', 'sort_order' => 1, 'probability' => 25,  'is_won' => false, 'is_lost' => false],
            ['name' => 'Qualified',   'color' => '#f59e0b', 'sort_order' => 2, 'probability' => 50,  'is_won' => false, 'is_lost' => false],
            ['name' => 'Proposal',    'color' => '#f97316', 'sort_order' => 3, 'probability' => 70,  'is_won' => false, 'is_lost' => false],
            ['name' => 'Won',         'color' => '#22c55e', 'sort_order' => 4, 'probability' => 100, 'is_won' => true,  'is_lost' => false],
            ['name' => 'Lost',        'color' => '#ef4444', 'sort_order' => 5, 'probability' => 0,   'is_won' => false, 'is_lost' => true],
        ];

        foreach ($stages as $stage) {
            $pipeline->stages()->create(array_merge($stage, ['organization_id' => $org->id]));
        }

        return $pipeline;
    }
}
