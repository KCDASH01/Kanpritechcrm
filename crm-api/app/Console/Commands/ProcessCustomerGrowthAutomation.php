<?php

namespace App\Console\Commands;

use App\Models\CustomerGrowthNotificationKey;
use App\Models\CustomerGrowthSetting;
use App\Models\GrowthRecommendation;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\RecurringBusiness;
use App\Models\User;
use App\Services\CustomerGrowthService;
use Illuminate\Console\Command;

class ProcessCustomerGrowthAutomation extends Command
{
    protected $signature = 'customer-growth:process {--organization=}';

    protected $description = 'Refresh customer growth recommendations and create deduplicated internal reminders.';

    public function handle(CustomerGrowthService $service): int
    {
        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($query, $id) => $query->whereKey($id))->pluck('id');

        foreach ($organizations as $organizationId) {
            $settings = $service->settings((int) $organizationId);
            $created = $service->refreshRecommendations((int) $organizationId);
            $this->line("Organization {$organizationId}: {$created} recommendation(s) created.");
            if ($settings->renewal_reminders_enabled) {
                $this->processRenewals((int) $organizationId, $settings);
            }
            if ($settings->cross_sell_enabled || $settings->upsell_enabled) {
                $this->processOpportunityFollowUps((int) $organizationId);
            }
            if ($settings->health_alerts_enabled) {
                $this->processHealthAlerts((int) $organizationId, $service);
            }
        }

        return self::SUCCESS;
    }

    private function processRenewals(int $organizationId, CustomerGrowthSetting $settings): void
    {
        $intervals = collect($settings->reminder_intervals ?: [30, 15, 7, 0, -1])->map(fn ($value) => (int) $value);
        RecurringBusiness::query()->where('organization_id', $organizationId)->where('status', 'ACTIVE')
            ->whereNotNull('end_date')->whereNotNull('assigned_to')->with('client:id,company,first_name,last_name')
            ->each(function (RecurringBusiness $contract) use ($organizationId, $intervals) {
                $days = now()->startOfDay()->diffInDays($contract->end_date->copy()->startOfDay(), false);
                if (! $intervals->contains($days)) {
                    return;
                }
                $key = "renewal:{$contract->id}:{$days}:{$contract->end_date->toDateString()}";
                $hash = hash('sha256', implode('|', [$contract->status, $contract->end_date->toDateString(), $contract->assigned_to]));
                if (! $this->claim($organizationId, $key, $hash)) {
                    return;
                }
                Notification::notify(
                    $organizationId, (int) $contract->assigned_to, 'customer_renewal',
                    $days < 0 ? 'Overdue customer renewal' : 'Customer renewal reminder',
                    ($contract->client?->company ?: $contract->client?->full_name ?: 'Customer')." — {$contract->business_name} ".($days < 0 ? 'expired' : 'renews')." on {$contract->end_date->format('d M Y')}.",
                    ['recurring_business_id' => $contract->id, 'client_id' => $contract->client_id],
                    '/customer-growth?tab=retention',
                );
            });
    }

    private function processOpportunityFollowUps(int $organizationId): void
    {
        GrowthRecommendation::query()->where('organization_id', $organizationId)->whereIn('status', ['new', 'under_review', 'contact_planned', 'contacted', 'interested'])
            ->whereNotNull('assigned_to')->whereDate('suggested_follow_up_date', '<=', now()->toDateString())
            ->each(function (GrowthRecommendation $recommendation) use ($organizationId) {
                $key = "growth-follow-up:{$recommendation->id}:{$recommendation->suggested_follow_up_date?->toDateString()}";
                $hash = hash('sha256', implode('|', [$recommendation->status, $recommendation->assigned_to, $recommendation->suggested_follow_up_date?->toDateString()]));
                if (! $this->claim($organizationId, $key, $hash)) {
                    return;
                }
                Notification::notify(
                    $organizationId, (int) $recommendation->assigned_to, 'growth_follow_up',
                    'Customer growth follow-up due',
                    "Review {$recommendation->suggested_service} opportunity.",
                    ['growth_recommendation_id' => $recommendation->id, 'client_id' => $recommendation->client_id],
                    '/customer-growth',
                );
            });
    }

    private function processHealthAlerts(int $organizationId, CustomerGrowthService $service): void
    {
        $admin = User::query()->where('organization_id', $organizationId)
            ->whereIn('role', ['owner', 'admin'])->first();
        if (! $admin) {
            return;
        }

        $service->health($admin)
            ->whereIn('status', ['attention_required', 'at_risk'])
            ->each(function (array $health) use ($organizationId) {
                if (empty($health['assigned_employee_id'])) {
                    return;
                }
                $key = "customer-health:{$health['client_id']}:{$health['status']}";
                $hash = hash('sha256', implode('|', [$health['status'], ...$health['reasons']]));
                if (! $this->claim($organizationId, $key, $hash)) {
                    return;
                }
                Notification::notify(
                    $organizationId, (int) $health['assigned_employee_id'], 'customer_health',
                    $health['status'] === 'at_risk' ? 'Customer requires attention' : 'Customer health reminder',
                    $health['client_name'].' — '.implode(' ', $health['reasons']),
                    ['client_id' => $health['client_id'], 'health_status' => $health['status']],
                    "/customer-growth?tab=retention&client_id={$health['client_id']}",
                );
            });
    }

    private function claim(int $organizationId, string $key, string $hash): bool
    {
        $record = CustomerGrowthNotificationKey::where('organization_id', $organizationId)->where('deduplication_key', $key)->first();
        if ($record && $record->condition_hash === $hash && ! $record->resolved_at) {
            return false;
        }
        CustomerGrowthNotificationKey::updateOrCreate(
            ['organization_id' => $organizationId, 'deduplication_key' => $key],
            ['condition_hash' => $hash, 'notified_at' => now(), 'resolved_at' => null],
        );

        return true;
    }
}
