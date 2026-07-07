<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire
                            {--dry-run : Preview which organizations would be downgraded without making changes}';

    protected $description = 'Downgrade expired Business subscriptions to Free automatically';

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
            $this->info("Done. {$count} organization(s) downgraded to Free.");
        }

        return self::SUCCESS;
    }

    // ── Dry-run: preview without writing ──────────────────────────────────────

    private function runDryRun(): int
    {
        $this->warn('[DRY-RUN] No changes will be made.');

        $rows = \App\Models\Subscription::where('is_active', true)
            ->where('plan', 'business')
            ->whereNotNull('end_date')
            ->where('end_date', '<', now())
            ->with('organization')
            ->get()
            ->map(fn ($sub) => [
                $sub->organization?->name ?? "Org #{$sub->organization_id}",
                $sub->end_date->toDateString(),
                $sub->subscription_source,
            ]);

        if ($rows->isEmpty()) {
            $this->info('No expired subscriptions found.');
            return self::SUCCESS;
        }

        $this->table(['Organization', 'Expired On', 'Source'], $rows);
        $this->warn("Would downgrade {$rows->count()} organization(s) to Free.");

        return self::SUCCESS;
    }
}
