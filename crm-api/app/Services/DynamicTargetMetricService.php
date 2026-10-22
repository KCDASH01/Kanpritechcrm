<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\TargetAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DynamicTargetMetricService
{
    public const SOURCES = [
        'manual' => 'Manual performance entry',
        'revenue_generated' => 'Revenue generated',
        'collections_received' => 'Collections received',
        'deals_closed' => 'Deals closed',
        'leads_assigned' => 'Leads assigned',
        'lead_conversion_ratio' => 'Lead conversion ratio',
    ];

    public function __construct(
        private readonly TargetProgressService $legacyProgress,
        private readonly LeadConversionRatioService $leadConversions,
    ) {}

    /** @return array<string, mixed> */
    public function progress(TargetAssignment $assignment, User $viewer): array
    {
        $members = $this->members($assignment);
        $from = $assignment->period_start->toDateString();
        $to = $assignment->period_end->toDateString();
        $actual = match ($assignment->metric_source) {
            'revenue_generated' => $this->legacyProgress->achieved($assignment->organization_id, $members->pluck('id'), $from, $to),
            'collections_received' => $this->legacyProgress->received($assignment->organization_id, $members->pluck('id'), $from, $to),
            'deals_closed' => $this->closedDeals($assignment, $members),
            'leads_assigned', 'lead_conversion_ratio' => $this->leadMetric($assignment, $viewer, $members),
            default => (float) $assignment->entries()->sum('actual_value'),
        };
        $target = (float) $assignment->target_value;
        $achievement = $this->achievement($target, $actual, $assignment->direction);
        $gap = $assignment->direction === 'lower'
            ? max(0, $actual - $target)
            : max(0, $target - $actual);

        return [
            'id' => (int) $assignment->id,
            'definition_id' => (int) $assignment->target_definition_id,
            'target_name' => $assignment->definition_name,
            'target_code' => $assignment->definition_code,
            'scope_type' => $assignment->scope_type,
            'user' => $assignment->user ? ['id' => $assignment->user->id, 'name' => $assignment->user->name] : null,
            'department' => $assignment->department ? ['id' => $assignment->department->id, 'name' => $assignment->department->name] : null,
            'period_type' => $assignment->period_type,
            'period_start' => $from,
            'period_end' => $to,
            'target_value' => round($target, 4),
            'actual_value' => round($actual, 4),
            'achievement_percentage' => $achievement,
            'remaining_value' => round($gap, 4),
            'unit_type' => $assignment->unit_type,
            'unit_label' => $assignment->unit_label,
            'metric_source' => $assignment->metric_source,
            'direction' => $assignment->direction,
            'status' => $this->displayStatus($assignment, $viewer),
            'assignment_status' => $assignment->status,
            'manual_entries' => $assignment->relationLoaded('entries') ? $assignment->entries : [],
        ];
    }

    /** @return Collection<int, User> */
    private function members(TargetAssignment $assignment): Collection
    {
        if ($assignment->scope_type === 'employee' && $assignment->user) {
            return collect([$assignment->user]);
        }

        return User::query()->where('organization_id', $assignment->organization_id)
            ->where('is_active', true)->where('role', '!=', 'owner')
            ->whereHas('departments', fn (Builder $query) => $query->where('departments.id', $assignment->department_id))
            ->get(['id', 'name']);
    }

    private function closedDeals(TargetAssignment $assignment, Collection $members): float
    {
        return (float) Deal::query()->where('organization_id', $assignment->organization_id)
            ->whereIn('assigned_to', $members->pluck('id'))->where('status', 'won')
            ->whereDate('closed_at', '>=', $assignment->period_start->toDateString())
            ->whereDate('closed_at', '<=', $assignment->period_end->toDateString())->count();
    }

    private function leadMetric(TargetAssignment $assignment, User $viewer, Collection $members): float
    {
        $period = $this->leadConversions->resolvePeriod($viewer, [
            'conversion_period' => 'custom',
            'conversion_start' => $assignment->period_start->toDateString(),
            'conversion_end' => $assignment->period_end->toDateString(),
        ]);
        $result = $this->leadConversions->calculate($assignment->organization_id, $members, $period)['team'];

        return $assignment->metric_source === 'lead_conversion_ratio'
            ? (float) $result['ratio']
            : (float) $result['assigned_leads'];
    }

    private function achievement(float $target, float $actual, string $direction): ?float
    {
        if ($target <= 0) {
            return null;
        }
        if ($direction === 'lower') {
            return $actual <= $target ? 100.0 : round(($target / max($actual, 0.0001)) * 100, 2);
        }

        return round(($actual / $target) * 100, 2);
    }

    private function displayStatus(TargetAssignment $assignment, User $viewer): string
    {
        if ($assignment->status !== 'active') {
            return $assignment->status;
        }
        $today = now($viewer->organization?->timezone ?: config('app.timezone'))->toDateString();
        if ($today < $assignment->period_start->toDateString()) {
            return 'upcoming';
        }

        return $today > $assignment->period_end->toDateString() ? 'completed' : 'active';
    }
}
