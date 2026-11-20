<?php

namespace App\Services;

use App\Models\SalesTarget;
use App\Models\TargetAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TargetCoachService
{
    public function __construct(
        private readonly DynamicTargetMetricService $dynamicMetrics,
        private readonly TargetProgressService $legacyProgress,
    ) {}

    /** @return array{targets: array<int, array<string, mixed>>, meta: array<string, mixed>} */
    public function summaries(User $viewer, ?int $requestedUserId = null): array
    {
        $timezone = $viewer->organization?->timezone ?: config('app.timezone');
        $today = Carbon::now($timezone)->startOfDay();
        $selectedUser = $this->selectedUser($viewer, $requestedUserId);
        $dynamic = $this->dynamicAssignments($viewer, $selectedUser, $today)
            ->map(function (TargetAssignment $assignment) use ($viewer, $today, $timezone) {
                $progress = $this->dynamicMetrics->progress($assignment, $viewer);

                return $this->analyse($progress, $assignment->period_start, $assignment->period_end, $timezone, $today)
                    + ['source' => 'dynamic'];
            });
        $legacy = $this->legacyTargets($viewer, $selectedUser, $today)
            ->flatMap(function (SalesTarget $target) use ($today, $timezone) {
                $progress = $this->legacyProgress->progressForTarget($target, $today);
                $base = [
                    'id' => 'legacy-sales-'.$target->id,
                    'assignment_id' => null,
                    'target_name' => 'Sales Target',
                    'scope_type' => 'employee',
                    'user' => $target->user ? ['id' => $target->user->id, 'name' => $target->user->name] : null,
                    'department' => null,
                    'period_type' => $target->target_type ?: 'monthly',
                    'period_start' => $progress['period_start'],
                    'period_end' => $progress['period_end'],
                    'target_value' => $progress['target_amount'],
                    'actual_value' => $progress['achieved_amount'],
                    'achievement_percentage' => $progress['sales_percentage'],
                    'remaining_value' => $progress['remaining_sales_amount'],
                    'unit_type' => 'currency',
                    // Legacy target values are INR-denominated throughout the existing dashboard.
                    'unit_label' => 'INR',
                    'metric_source' => 'revenue_generated',
                    'direction' => 'higher',
                    'status' => $progress['period_status'],
                ];
                $rows = [$this->analyse($base, $target->period_start, $this->legacyProgress->periodEnd($target), $timezone, $today) + ['source' => 'legacy']];
                if ((float) $target->receivable_amount > 0) {
                    $collection = $base;
                    $collection['id'] = 'legacy-collection-'.$target->id;
                    $collection['target_name'] = 'Collection Target';
                    $collection['target_value'] = $progress['receivable_amount'];
                    $collection['actual_value'] = $progress['received_amount'];
                    $collection['achievement_percentage'] = $progress['collection_percentage'];
                    $collection['remaining_value'] = $progress['remaining_collection_amount'];
                    $collection['metric_source'] = 'collections_received';
                    $rows[] = $this->analyse($collection, $target->period_start, $this->legacyProgress->periodEnd($target), $timezone, $today) + ['source' => 'legacy'];
                }

                return $rows;
            });

        return [
            'targets' => $legacy->concat($dynamic)->values()->take(50)->all(),
            'meta' => [
                'timezone' => $timezone,
                'working_day_rule' => 'Monday to Friday; no holiday calendar is configured.',
                'as_of' => $today->toDateString(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function analyse(array $progress, mixed $start, mixed $end, string $timezone, ?Carbon $today = null): array
    {
        $startDate = Carbon::parse($start, $timezone)->startOfDay();
        $endDate = Carbon::parse($end, $timezone)->startOfDay();
        $today ??= Carbon::now($timezone)->startOfDay();
        $elapsedEnd = $today->lt($startDate) ? null : ($today->lt($endDate) ? $today : $endDate);
        $remainingStart = $today->lt($startDate) ? $startDate : $today->copy()->addDay();
        $elapsed = $elapsedEnd ? $this->workingDays($startDate, $elapsedEnd) : 0;
        $remaining = $remainingStart->lte($endDate) ? $this->workingDays($remainingStart, $endDate) : 0;
        $actual = (float) ($progress['actual_value'] ?? 0);
        $target = (float) ($progress['target_value'] ?? 0);
        $direction = (string) ($progress['direction'] ?? 'higher');
        $actualRate = $elapsed > 0 ? $actual / $elapsed : null;
        $shortfall = $direction === 'lower' ? max(0, $actual - $target) : max(0, $target - $actual);
        $requiredRate = $direction === 'higher' && $remaining > 0 ? $shortfall / $remaining : null;
        $projectionSupported = ! in_array($progress['unit_type'] ?? '', ['percentage'], true)
            && ($progress['metric_source'] ?? '') !== 'lead_conversion_ratio'
            && $actualRate !== null;
        $projected = $projectionSupported ? $actual + ($actualRate * $remaining) : null;
        $projectedGap = $projected === null ? null : ($direction === 'lower' ? max(0, $projected - $target) : max(0, $target - $projected));
        $projectedPct = $projected === null || $target <= 0 ? null : round(($projected / $target) * 100, 2);
        $status = $this->projectionStatus($progress, $target, $actual, $projected, $today, $startDate, $endDate);

        return $progress + [
            'elapsed_working_days' => $elapsed,
            'remaining_working_days' => $remaining,
            'actual_daily_run_rate' => $actualRate === null ? null : round($actualRate, 4),
            'required_daily_run_rate' => $requiredRate === null ? null : round($requiredRate, 4),
            'projected_value' => $projected === null ? null : round($projected, 4),
            'projected_shortfall' => $projectedGap === null ? null : round($projectedGap, 4),
            'projected_percentage' => $projectedPct,
            'projection_status' => $status,
            'projection_available' => $projectionSupported,
            'projection_note' => $projectionSupported ? null : 'Projection unavailable for this target type.',
        ];
    }

    private function selectedUser(User $viewer, ?int $requestedUserId): ?User
    {
        if (! $viewer->isAdmin() && ! $viewer->isOwner()) {
            return $viewer;
        }
        if (! $requestedUserId) {
            return null;
        }

        return User::query()->where('organization_id', $viewer->organization_id)->where('is_active', true)->findOrFail($requestedUserId);
    }

    /** @return Collection<int, TargetAssignment> */
    private function dynamicAssignments(User $viewer, ?User $selectedUser, Carbon $today): Collection
    {
        $query = TargetAssignment::query()->where('organization_id', $viewer->organization_id)
            ->where('status', 'active')->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today)
            ->with(['user:id,name,organization_id', 'department:id,name', 'entries']);
        if ($selectedUser) {
            $departmentIds = $selectedUser->departments()->pluck('departments.id');
            $query->where(function (Builder $scope) use ($selectedUser, $departmentIds) {
                $scope->where(fn (Builder $employee) => $employee->where('scope_type', 'employee')->where('user_id', $selectedUser->id))
                    ->orWhere(fn (Builder $department) => $department->where('scope_type', 'department_shared')->whereIn('department_id', $departmentIds));
            });
        }

        return $query->orderBy('period_end')->limit(50)->get();
    }

    /** @return Collection<int, SalesTarget> */
    private function legacyTargets(User $viewer, ?User $selectedUser, Carbon $today): Collection
    {
        return $this->legacyProgress->targetsForDate((int) $viewer->organization_id, $today)
            ->when($selectedUser, fn (Collection $targets) => $targets->where('user_id', $selectedUser->id)->values());
    }

    private function workingDays(Carbon $start, Carbon $end): int
    {
        if ($start->gt($end)) {
            return 0;
        }
        $days = 0;
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if ($date->isWeekday()) {
                $days++;
            }
        }

        return $days;
    }

    private function projectionStatus(array $progress, float $target, float $actual, ?float $projected, Carbon $today, Carbon $start, Carbon $end): string
    {
        if ($today->lt($start)) {
            return 'upcoming';
        }
        if ($target <= 0 || $projected === null) {
            return 'unavailable';
        }
        $direction = (string) ($progress['direction'] ?? 'higher');
        if ($direction === 'higher' && $actual > $target) {
            return 'exceeded';
        }
        if ($direction === 'higher' && $actual >= $target) {
            return 'achieved';
        }
        if ($direction === 'lower') {
            return $projected <= $target ? 'on_track' : ($projected <= $target * 1.15 ? 'at_risk' : 'behind');
        }
        $ratio = $projected / $target;
        if ($ratio >= 1) {
            return 'on_track';
        }
        if ($ratio >= 0.85) {
            return 'at_risk';
        }

        return 'behind';
    }
}
