<?php

namespace App\Services\LeadIntegrations;

use App\Models\IntegrationCampaign;
use App\Models\LeadIntegrationSetting;
use App\Models\LeadRoutingRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeadRoutingService
{
    /**
     * @return array{user_id: ?int, department_id: ?int, reason: string}
     */
    public function resolve(int $organizationId, array $context): array
    {
        return $this->resolveFor($organizationId, $context, true);
    }

    /**
     * Preview routing without advancing a round-robin cursor.
     *
     * @return array{user_id: ?int, department_id: ?int, reason: string}
     */
    public function preview(int $organizationId, array $context): array
    {
        return $this->resolveFor($organizationId, $context, false);
    }

    /**
     * @return array{user_id: ?int, department_id: ?int, reason: string}
     */
    private function resolveFor(int $organizationId, array $context, bool $advanceRoundRobin): array
    {
        $campaign = $this->campaign($organizationId, $context);
        if ($campaign?->assigned_to && $this->eligible($organizationId, $campaign->assigned_to)) {
            return ['user_id' => $campaign->assigned_to, 'department_id' => null, 'reason' => 'campaign_mapping'];
        }

        $rules = LeadRoutingRule::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $context)) {
                continue;
            }

            $userId = $this->userForRule($rule, $advanceRoundRobin);
            if ($userId !== null) {
                return [
                    'user_id' => $userId,
                    'department_id' => $rule->department_id,
                    'reason' => 'routing_rule:'.$rule->id,
                ];
            }
        }

        $settings = LeadIntegrationSetting::query()->where('organization_id', $organizationId)->first();
        if ($settings?->default_owner_id && $this->eligible($organizationId, $settings->default_owner_id)) {
            return ['user_id' => $settings->default_owner_id, 'department_id' => null, 'reason' => 'default_owner'];
        }

        return ['user_id' => null, 'department_id' => null, 'reason' => 'review_queue'];
    }

    private function campaign(int $organizationId, array $context): ?IntegrationCampaign
    {
        $externalId = $context['campaign_id'] ?? null;
        if (! is_string($externalId) || $externalId === '') {
            return null;
        }

        return IntegrationCampaign::query()
            ->where('organization_id', $organizationId)
            ->where('platform', $context['provider'] ?? '')
            ->where('external_id', $externalId)
            ->where('status', 'active')
            ->first();
    }

    private function matches(LeadRoutingRule $rule, array $context): bool
    {
        $value = match ($rule->source_type) {
            'campaign' => $context['campaign_id'] ?? null,
            'ad' => $context['ad_id'] ?? null,
            'form' => $context['form_id'] ?? null,
            'alias' => $context['receiving_alias'] ?? null,
            'mailbox' => $context['mailbox_id'] ?? null,
            'authorized_sender' => isset($context['sender']) ? mb_strtolower((string) $context['sender']) : null,
            'provider' => $context['provider'] ?? null,
            'country' => $context['country'] ?? null,
            'service' => $context['service'] ?? null,
            'default' => 'default',
            default => null,
        };

        return $rule->source_type === 'default'
            || ($value !== null && (string) $rule->source_key === (string) $value);
    }

    private function userForRule(LeadRoutingRule $rule, bool $advanceRoundRobin = true): ?int
    {
        if ($rule->strategy === 'round_robin') {
            return DB::transaction(function () use ($rule, $advanceRoundRobin): ?int {
                $locked = LeadRoutingRule::query()->lockForUpdate()->find($rule->id);
                if (! $locked) {
                    return null;
                }

                $selectedIds = array_values(array_filter(array_map('intval', (array) data_get($locked->settings, 'user_ids', []))));
                $ids = User::query()
                    ->where('users.organization_id', $locked->organization_id)
                    ->where('users.is_active', true)
                    ->whereIn('users.role', ['owner', 'admin', 'employee'])
                    ->when($selectedIds !== [], fn ($query) => $query->whereIn('users.id', $selectedIds))
                    ->when($selectedIds === [] && $locked->department_id, fn ($query) => $query->whereHas('departments', fn ($department) => $department->where('departments.id', $locked->department_id)))
                    ->orderBy('users.id')
                    ->pluck('users.id')
                    ->all();

                if ($ids === []) {
                    return $this->fallback($locked);
                }

                $position = array_search($locked->last_assigned_user_id, $ids, true);
                $next = $ids[$position === false ? 0 : (($position + 1) % count($ids))];
                if ($advanceRoundRobin) {
                    $locked->update(['last_assigned_user_id' => $next]);
                }

                return $next;
            });
        }

        if ($rule->assigned_to && $this->eligible($rule->organization_id, $rule->assigned_to)) {
            return $rule->assigned_to;
        }

        return $this->fallback($rule);
    }

    private function fallback(LeadRoutingRule $rule): ?int
    {
        return $rule->backup_user_id && $this->eligible($rule->organization_id, $rule->backup_user_id)
            ? $rule->backup_user_id
            : null;
    }

    private function eligible(int $organizationId, int $userId): bool
    {
        return User::query()
            ->whereKey($userId)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('role', ['owner', 'admin', 'employee'])
            ->exists();
    }
}
