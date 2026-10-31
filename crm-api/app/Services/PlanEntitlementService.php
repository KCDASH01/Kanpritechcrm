<?php

namespace App\Services;

use App\Models\Organization;

class PlanEntitlementService
{
    public function limits(Organization $organization): array
    {
        $subscription = $organization->activeSubscription();

        // Rows created before recurring billing keep their historical limits.
        if ($subscription && ! $subscription->billing_cycle) {
            return match ($subscription->plan) {
                'enterprise' => ['users' => 10, 'leads' => null, 'deals' => null, 'pipelines' => null],
                'business' => ['users' => 3, 'leads' => 5000, 'deals' => 2000, 'pipelines' => 30],
                default => ['users' => 0, 'leads' => 100, 'deals' => 50, 'pipelines' => 1],
            };
        }

        return match ($subscription?->plan) {
            'growth' => ['users' => 3, 'leads' => 5000, 'deals' => null, 'pipelines' => 5],
            'business' => ['users' => 10, 'leads' => 25000, 'deals' => null, 'pipelines' => null],
            'enterprise' => ['users' => null, 'leads' => null, 'deals' => null, 'pipelines' => null],
            default => ['users' => 0, 'leads' => 0, 'deals' => 0, 'pipelines' => 0],
        };
    }

    public function has(Organization $organization, string $feature): bool
    {
        $subscription = $organization->activeSubscription();
        if (! $subscription) {
            return false;
        }

        // Preserve all existing legacy paid-plan access.
        if (! $subscription->billing_cycle) {
            return in_array($subscription->plan, ['business', 'enterprise'], true);
        }

        return match ($feature) {
            'standard_reporting', 'basic_targets', 'fixed_assignment' => in_array($subscription->plan, ['growth', 'business', 'enterprise'], true),
            'advanced_reporting', 'dynamic_targets', 'round_robin', 'advanced_integrations' => in_array($subscription->plan, ['business', 'enterprise'], true),
            // Deliberately off until Meta/WhatsApp approval and production readiness are recorded.
            'meta_whatsapp' => false,
            default => false,
        };
    }
}
