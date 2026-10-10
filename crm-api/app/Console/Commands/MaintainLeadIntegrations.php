<?php

namespace App\Console\Commands;

use App\Jobs\RenewMailboxSubscription;
use App\Jobs\SyncMailbox;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEvent;
use App\Models\LeadIntegrationSetting;
use Illuminate\Console\Command;

class MaintainLeadIntegrations extends Command
{
    protected $signature = 'lead-integrations:maintain';

    protected $description = 'Queue mailbox reconciliation and remove expired raw integration payloads';

    public function handle(): int
    {
        IntegrationConnection::query()->where('status', 'active')->whereIn('provider', ['google', 'microsoft'])
            ->eachById(function (IntegrationConnection $connection): void {
                SyncMailbox::dispatch($connection->id);
                RenewMailboxSubscription::dispatch($connection->id);
            });

        LeadIntegrationSetting::query()->eachById(function (LeadIntegrationSetting $settings): void {
            IntegrationEvent::query()->where('organization_id', $settings->organization_id)
                ->whereNotNull('payload')->where('created_at', '<', now()->subDays($settings->raw_content_retention_days))
                ->update(['payload' => null]);
        });

        $this->info('Lead integration maintenance queued.');

        return self::SUCCESS;
    }
}
