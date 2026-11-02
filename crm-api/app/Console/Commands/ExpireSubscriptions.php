<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire
                            {--dry-run : Preview which organizations would be downgraded without making changes}';

    protected $description = 'Expire recurring subscriptions and preserve the legacy downgrade policy';

    public function __construct(private readonly SubscriptionService $subscriptionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            return $this->runDryRun();
        }

        $this->info('Checking for expired Business subscriptions…');

        $count = $this->subscriptionService->downgradeExpired();

        if ($count === 0) {
            $this->info('No expired subscriptions found. Nothing to do.');
        } else {
            $this->info("Done. {$count} subscription record(s) processed.");
        }

        return self::SUCCESS;
    }

    // ── Dry-run: preview without writing ──────────────────────────────────────

    private function runDryRun(): int
    {
        $this->warn('[DRY-RUN] No changes will be made.');

        $modern = $this->subscriptionService->recurringSubscriptionsDueForExpiry();
        $legacyOrgIds = $this->subscriptionService->legacyOrganizationIdsDueForDowngrade();

        if ($modern->isEmpty() && $legacyOrgIds->isEmpty()) {
            $this->info('No expired subscriptions found.');

            return self::SUCCESS;
        }

        if ($modern->isNotEmpty()) {
            $this->info('Modern recurring subscriptions:');
            $this->table(
                ['Subscription', 'Organization', 'Plan', 'Access Ended', 'Action'],
                $modern->map(fn (Subscription $subscription) => [
                    "#{$subscription->id}",
                    $subscription->organization?->name ?? "Org #{$subscription->organization_id}",
                    ucfirst($subscription->plan),
                    ($subscription->access_until ?? $subscription->end_date)?->toDateTimeString() ?? 'Unknown',
                    'Mark expired; retain read-only access',
                ])
            );
        }

        if ($legacyOrgIds->isNotEmpty()) {
            $organizations = Organization::with('owner')->whereIn('id', $legacyOrgIds)->get()->keyBy('id');
            $legacyRows = $legacyOrgIds->map(function (int $organizationId) use ($organizations) {
                $organization = $organizations->get($organizationId);
                $hasFallbackOwner = $organization?->owner || $organization?->users()->exists();

                return [
                    $organization?->name ?? "Org #{$organizationId}",
                    $hasFallbackOwner ? 'Expire paid rows; assign Free' : 'Expire paid rows; cannot assign Free (no owner)',
                ];
            });

            $this->info('Legacy subscriptions:');
            $this->table(['Organization', 'Action'], $legacyRows);
        }

        $this->warn("Would expire {$modern->count()} recurring subscription(s) and process {$legacyOrgIds->count()} legacy organization(s).");

        return self::SUCCESS;
    }
}
