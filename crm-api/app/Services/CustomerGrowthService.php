<?php

namespace App\Services;

use App\Models\Client;
use App\Models\CustomerGrowthSetting;
use App\Models\Deal;
use App\Models\GrowthRecommendation;
use App\Models\RecurringBusiness;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CustomerGrowthService
{
    public function __construct(private readonly RecurringRevenueService $recurringRevenue) {}

    public function settings(int $organizationId): CustomerGrowthSetting
    {
        return CustomerGrowthSetting::firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'reminder_intervals' => [30, 15, 7, 0, -1],
                'service_mappings' => [],
                'health_thresholds' => ['renewal_attention_days' => 30, 'material_overdue_amount' => null],
                'notification_channels' => ['in_app'],
                'default_assignment' => 'client_owner',
            ],
        );
    }

    public function refreshRecommendations(int $organizationId): int
    {
        $settings = $this->settings($organizationId);
        $mappings = collect($settings->service_mappings ?? [])->filter(fn ($row) => is_array($row) && ! empty($row['source']) && ! empty($row['target']));
        if ($mappings->isEmpty()) {
            return 0;
        }

        $clients = Client::query()
            ->where('organization_id', $organizationId)
            ->with([
                'deals' => fn ($query) => $query->where('status', 'won')->with('lead:id,types'),
                'recurringBusinesses' => fn ($query) => $query->where('status', 'ACTIVE')->whereNotNull('service_type'),
            ])->get();

        $created = 0;
        foreach ($clients as $client) {
            $purchases = collect();
            foreach ($client->deals as $deal) {
                $service = $deal->service_type ?: $deal->lead?->types;
                if ($service) {
                    $purchases->push(['service' => $service, 'deal_id' => $deal->id, 'recurring_id' => null]);
                }
            }
            foreach ($client->recurringBusinesses as $contract) {
                $purchases->push(['service' => $contract->service_type, 'deal_id' => $contract->deal_id, 'recurring_id' => $contract->id]);
            }
            $purchasedKeys = $purchases->pluck('service')->map(fn ($value) => $this->serviceKey((string) $value))->unique();

            foreach ($purchases->unique('service') as $purchase) {
                $sourceKey = $this->serviceKey((string) $purchase['service']);
                foreach ($mappings->filter(fn ($mapping) => $this->serviceKey((string) $mapping['source']) === $sourceKey) as $mapping) {
                    $type = strtolower((string) ($mapping['type'] ?? 'cross_sell'));
                    if (($type === 'cross_sell' && ! $settings->cross_sell_enabled) || ($type === 'upsell' && ! $settings->upsell_enabled)) {
                        continue;
                    }
                    $target = trim((string) $mapping['target']);
                    if ($purchasedKeys->contains($this->serviceKey($target))) {
                        continue;
                    }

                    $fingerprint = hash('sha256', implode('|', [$client->id, $sourceKey, $this->serviceKey($target), $type]));
                    $estimate = $this->comparableEstimate($organizationId, $target, (int) $client->id);
                    $recommendation = GrowthRecommendation::firstOrCreate(
                        ['organization_id' => $organizationId, 'fingerprint' => $fingerprint],
                        [
                            'client_id' => $client->id,
                            'source_deal_id' => $purchase['deal_id'],
                            'source_recurring_business_id' => $purchase['recurring_id'],
                            'assigned_to' => $client->assigned_to,
                            'existing_service' => $purchase['service'],
                            'suggested_service' => $target,
                            'recommendation_type' => $type === 'upsell' ? 'upsell' : 'cross_sell',
                            'reason' => trim((string) ($mapping['reason'] ?? 'Configured service recommendation mapping.')),
                            'potential_value' => $estimate?->value,
                            'currency' => $estimate?->currency,
                            'estimate_source' => $estimate ? "Comparable won deal #{$estimate->id}" : null,
                            'priority' => in_array(($mapping['priority'] ?? 'medium'), ['low', 'medium', 'high'], true) ? ($mapping['priority'] ?? 'medium') : 'medium',
                            'suggested_follow_up_date' => now()->addDays(7)->toDateString(),
                            'status' => 'new',
                            'metadata' => ['rule' => $mapping],
                        ],
                    );
                    if ($recommendation->wasRecentlyCreated) {
                        $created++;
                    }
                }
            }
        }

        return $created;
    }

    public function overview(User $user, array $filters): array
    {
        $query = GrowthRecommendation::query()
            ->where('organization_id', $user->organization_id)
            ->with(['client:id,first_name,last_name,company,assigned_to', 'assignedTo:id,name', 'convertedDeal:id,status,value,currency']);
        $this->scopeAssigned($query, $user, 'assigned_to');
        foreach (['status', 'priority', 'recommendation_type', 'client_id', 'assigned_to'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['service'])) {
            $query->where(fn ($builder) => $builder->where('existing_service', $filters['service'])->orWhere('suggested_service', $filters['service']));
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $recommendations = $query->latest()->get();
        $money = fn (Collection $rows, string $field) => $rows->filter(fn ($row) => $row->{$field} !== null)
            ->groupBy(fn ($row) => $field === 'potential_value' ? ($row->currency ?: 'INR') : ($row->convertedDeal?->currency ?: 'INR'))
            ->map(fn ($currencyRows, $currency) => [
                'currency' => $currency,
                'amount' => round((float) $currencyRows->sum(fn ($row) => $field === 'potential_value' ? (float) $row->potential_value : (float) ($row->convertedDeal?->value ?? 0)), 2),
            ])->values();

        return [
            'summary' => [
                'total' => $recommendations->count(),
                'potential_revenue' => $money($recommendations, 'potential_value'),
                'cross_sell' => $recommendations->where('recommendation_type', 'cross_sell')->count(),
                'upsell' => $recommendations->where('recommendation_type', 'upsell')->count(),
                'converted' => $recommendations->where('status', 'converted')->count(),
                'actual_won_value' => $money($recommendations->filter(fn ($row) => $row->convertedDeal?->status === 'won'), 'converted_deal'),
            ],
            'recommendations' => $recommendations,
            'health' => $this->health($user),
            'renewals' => $this->renewals($user),
            'settings' => $this->settings($user->organization_id),
        ];
    }

    public function health(User $user): Collection
    {
        $settings = $this->settings($user->organization_id);
        $attentionDays = (int) data_get($settings->health_thresholds, 'renewal_attention_days', 30);
        $overdueThreshold = data_get($settings->health_thresholds, 'material_overdue_amount');

        $query = Client::query()->where('organization_id', $user->organization_id)
            ->with(['assignedTo:id,name', 'recurringBusinesses.deal.payments']);
        if (! $user->isAdmin()) {
            $query->where('assigned_to', $user->id);
        }

        return $query->get()->map(function (Client $client) use ($attentionDays, $overdueThreshold) {
            $contracts = $client->recurringBusinesses;
            $reasons = [];
            $status = 'insufficient_data';
            if ($contracts->isNotEmpty()) {
                $active = $contracts->where('status', 'ACTIVE');
                $cancelled = $contracts->whereIn('status', ['CANCELLED', 'EXPIRED', 'COMPLETED']);
                if ($cancelled->isNotEmpty() && $active->isEmpty()) {
                    $status = 'inactive';
                    $reasons[] = 'No active recurring contract; a contract is cancelled, expired or completed.';
                } else {
                    $status = 'healthy';
                    foreach ($active as $contract) {
                        if ($contract->end_date && $contract->end_date->isPast()) {
                            $status = 'at_risk';
                            $reasons[] = "{$contract->business_name} expired on {$contract->end_date->toDateString()}.";
                        } elseif ($contract->end_date && now()->diffInDays($contract->end_date, false) <= $attentionDays) {
                            if ($status !== 'at_risk') {
                                $status = 'attention_required';
                            }
                            $reasons[] = "{$contract->business_name} renewal is approaching.";
                        }
                        if ($overdueThreshold !== null && $contract->deal) {
                            $due = $this->recurringRevenue->dueAmount($contract, now());
                            $collected = (float) $contract->deal->payments->sum('amount');
                            if (($due - $collected) >= (float) $overdueThreshold) {
                                $status = 'at_risk';
                                $reasons[] = 'Recorded collections are below due recurring revenue by '.round($due - $collected, 2).'.';
                            }
                        }
                    }
                    if ($status === 'healthy') {
                        $reasons[] = 'Active recurring contract with no configured risk condition detected.';
                    }
                }
            }

            return [
                'client_id' => $client->id,
                'client_name' => $client->company ?: $client->full_name,
                'assigned_employee_id' => $client->assigned_to,
                'assigned_employee' => $client->assignedTo?->name,
                'status' => $status,
                'reasons' => array_values(array_unique($reasons)),
            ];
        });
    }

    public function renewals(User $user): Collection
    {
        $query = RecurringBusiness::query()->where('organization_id', $user->organization_id)
            ->whereNotNull('end_date')->with(['client:id,first_name,last_name,company,assigned_to', 'assignedTo:id,name']);
        if (! $user->isAdmin()) {
            $query->where('assigned_to', $user->id);
        }

        return $query->orderBy('end_date')->get()->map(function (RecurringBusiness $contract) {
            $days = now()->startOfDay()->diffInDays($contract->end_date->copy()->startOfDay(), false);
            $renewalStatus = in_array($contract->status, ['CANCELLED', 'COMPLETED'], true)
                ? strtolower($contract->status)
                : ($days < 0 ? 'overdue' : ($days === 0 ? 'due_today' : 'upcoming'));

            return [
                'id' => $contract->id,
                'client_id' => $contract->client_id,
                'client_name' => $contract->client?->company ?: $contract->client?->full_name,
                'service' => $contract->service_type ?: $contract->business_name,
                'start_date' => $contract->start_date?->toDateString(),
                'renewal_date' => $contract->end_date?->toDateString(),
                'contract_value' => $contract->contract_value,
                'currency' => $contract->currency,
                'responsible_employee' => $contract->assignedTo?->name,
                'days_remaining' => $days,
                'status' => $renewalStatus,
            ];
        });
    }

    private function comparableEstimate(int $organizationId, string $service, int $excludeClientId): ?Deal
    {
        return Deal::query()->where('organization_id', $organizationId)->where('status', 'won')
            ->whereRaw("LOWER(TRIM(REPLACE(REPLACE(service_type, '_', ' '), '-', ' '))) = ?", [$this->serviceKey($service)])
            ->where('client_id', '!=', $excludeClientId)->whereNotNull('value')->latest('closed_at')->first();
    }

    private function serviceKey(string $value): string
    {
        $value = preg_replace('/[_-]+/', ' ', $value) ?? $value;

        return strtolower(trim(preg_replace('/\\s+/', ' ', $value) ?? $value));
    }

    private function scopeAssigned(Builder $query, User $user, string $column): void
    {
        if (! $user->isAdmin()) {
            $query->where($column, $user->id);
        }
    }
}
