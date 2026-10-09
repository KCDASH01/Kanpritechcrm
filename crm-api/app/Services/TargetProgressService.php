<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\SalesTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TargetProgressService
{
    /** @return array{0:string,1:string,2:string} */
    public function period(?string $month = null): array
    {
        $start = $month
            ? Carbon::createFromFormat('Y-m', $month)->startOfMonth()
            : now()->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString(), $start->format('F Y')];
    }

    /** @return Collection<int, int> */
    public function eligibleUserIds(int $organizationId): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('role', '!=', 'owner')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    public function periodEnd(SalesTarget $target): Carbon
    {
        return $target->period_end
            ? Carbon::parse($target->period_end)->startOfDay()
            : Carbon::parse($target->period_start)->endOfMonth()->startOfDay();
    }

    public function periodStatus(SalesTarget $target, ?Carbon $at = null): string
    {
        $at ??= now();
        $date = $at->copy()->startOfDay();
        $start = Carbon::parse($target->period_start)->startOfDay();
        $end = $this->periodEnd($target);

        if ($date->lt($start)) return 'upcoming';
        if ($date->gt($end)) return 'completed';
        return 'active';
    }

    public function activeTarget(int $organizationId, int $userId, ?Carbon $at = null): ?SalesTarget
    {
        $date = ($at ?? now())->toDateString();

        return SalesTarget::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereDate('period_start', '<=', $date)
            ->where(function (Builder $query) use ($date) {
                $query->whereDate('period_end', '>=', $date)
                    ->orWhere(function (Builder $legacy) use ($date) {
                        $legacy->whereNull('period_end')
                            ->whereDate('period_start', '>=', Carbon::parse($date)->startOfMonth()->toDateString());
                    });
            })
            ->orderByDesc('period_start')
            ->first();
    }

    /** @return Collection<int, SalesTarget> */
    public function targetsForDate(int $organizationId, Carbon $at, ?int $departmentId = null): Collection
    {
        $date = $at->toDateString();

        return SalesTarget::query()
            ->where('organization_id', $organizationId)
            ->whereDate('period_start', '<=', $date)
            ->where(function (Builder $query) use ($date) {
                $query->whereDate('period_end', '>=', $date)
                    ->orWhere(function (Builder $legacy) use ($date) {
                        $legacy->whereNull('period_end')
                            ->whereDate('period_start', '>=', Carbon::parse($date)->startOfMonth()->toDateString());
                    });
            })
            ->whereHas('user', function (Builder $user) use ($departmentId) {
                $user->where('is_active', true)->where('role', '!=', 'owner')
                    ->when($departmentId, fn (Builder $query) => $query->whereHas('departments', fn (Builder $department) => $department->where('departments.id', $departmentId)));
            })
            ->with(['user:id,name,email', 'user.departments:id,name'])
            ->orderBy('user_id')
            ->get();
    }

    /** @return array<string, float|int|string|bool|null|array> */
    public function progressForTarget(SalesTarget $target): array
    {
        $start = Carbon::parse($target->period_start)->toDateString();
        $end = $this->periodEnd($target)->toDateString();
        $achieved = $this->achieved((int) $target->organization_id, (int) $target->user_id, $start, $end);
        $collected = $this->received((int) $target->organization_id, (int) $target->user_id, $start, $end);
        $salesTarget = (float) $target->target_amount;
        $collectionTarget = (float) $target->receivable_amount;
        $today = now()->startOfDay();
        $startDate = Carbon::parse($start);
        $endDate = Carbon::parse($end);
        $duration = $startDate->diffInDays($endDate) + 1;
        $elapsed = $today->lt($startDate) ? 0 : min($duration, $startDate->diffInDays($today) + 1);
        $remainingDays = $today->gt($endDate) ? 0 : max(0, $today->diffInDays($endDate) + 1);

        return [
            'target_id' => (int) $target->id,
            'target_type' => $target->target_type ?: 'monthly',
            'period_start' => $start,
            'period_end' => $end,
            'period_label' => $startDate->format('d M Y').' – '.$endDate->format('d M Y'),
            'period_status' => $this->periodStatus($target),
            'duration_days' => $duration,
            'days_elapsed' => $elapsed,
            'days_remaining' => $remainingDays,
            'has_target' => true,
            'target_count' => 1,
            'target_amount' => round($salesTarget, 2),
            'achieved_amount' => round($achieved, 2),
            'sales_percentage' => $salesTarget > 0 ? round(($achieved / $salesTarget) * 100, 2) : null,
            'remaining_sales_amount' => round(max(0, $salesTarget - $achieved), 2),
            'receivable_amount' => round($collectionTarget, 2),
            'received_amount' => round($collected, 2),
            'collection_percentage' => $collectionTarget > 0 ? round(($collected / $collectionTarget) * 100, 2) : null,
            'remaining_collection_amount' => round(max(0, $collectionTarget - $collected), 2),
            'scope' => 'member',
            'user_id' => (int) $target->user_id,
        ];
    }

    /** @return array<string, float|int|string|bool|null|array> */
    public function activeTeamProgress(int $organizationId, ?Carbon $at = null): array
    {
        $at ??= now();
        $targets = $this->targetsForDate($organizationId, $at);
        $items = $targets->map(fn (SalesTarget $target) => $this->progressForTarget($target));

        $salesTarget = (float) $items->sum('target_amount');
        $achieved = (float) $items->sum('achieved_amount');
        $collectionTarget = (float) $items->sum('receivable_amount');
        $collected = (float) $items->sum('received_amount');

        return [
            'period_start' => $items->min('period_start') ?: $at->copy()->startOfMonth()->toDateString(),
            'period_end' => $items->max('period_end') ?: $at->copy()->endOfMonth()->toDateString(),
            'period_label' => 'Active target periods',
            'period_status' => 'active',
            'has_target' => $items->isNotEmpty(),
            'target_count' => $items->count(),
            'target_amount' => round($salesTarget, 2),
            'achieved_amount' => round($achieved, 2),
            'sales_percentage' => $salesTarget > 0 ? round(($achieved / $salesTarget) * 100, 2) : null,
            'remaining_sales_amount' => round(max(0, $salesTarget - $achieved), 2),
            'receivable_amount' => round($collectionTarget, 2),
            'received_amount' => round($collected, 2),
            'collection_percentage' => $collectionTarget > 0 ? round(($collected / $collectionTarget) * 100, 2) : null,
            'remaining_collection_amount' => round(max(0, $collectionTarget - $collected), 2),
            'days_elapsed' => null,
            'days_remaining' => null,
            'scope' => 'team',
            'user_id' => null,
            'periods' => $items->map(fn (array $item) => [
                'user_id' => $item['user_id'],
                'period_start' => $item['period_start'],
                'period_end' => $item['period_end'],
            ])->values()->all(),
        ];
    }

    /** Compatibility entry point used by existing dashboard/tests. */
    public function progress(int $organizationId, ?int $userId, string $periodStart, string $periodEnd): array
    {
        if ($userId) {
            $target = SalesTarget::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->whereDate('period_start', '<=', $periodEnd)
                ->where(function (Builder $query) use ($periodStart) {
                    $query->whereDate('period_end', '>=', $periodStart)
                        ->orWhereNull('period_end');
                })
                ->orderByDesc('period_start')
                ->first();

            if ($target) return $this->progressForTarget($target);
        }

        $eligibleIds = $userId ? collect([$userId]) : $this->eligibleUserIds($organizationId);
        $targetQuery = SalesTarget::query()
            ->where('organization_id', $organizationId)
            ->whereDate('period_start', '<=', $periodEnd)
            ->where(function (Builder $query) use ($periodStart) {
                $query->whereDate('period_end', '>=', $periodStart)->orWhereNull('period_end');
            })
            ->whereIn('user_id', $eligibleIds);
        $targets = $targetQuery->get();
        $salesTarget = (float) $targets->sum('target_amount');
        $collectionTarget = (float) $targets->sum('receivable_amount');
        $achieved = $this->achieved($organizationId, $eligibleIds, $periodStart, $periodEnd);
        $collected = $this->received($organizationId, $eligibleIds, $periodStart, $periodEnd);

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'period_label' => Carbon::parse($periodStart)->format('d M Y').' – '.Carbon::parse($periodEnd)->format('d M Y'),
            'has_target' => $targets->isNotEmpty(),
            'target_count' => $targets->count(),
            'target_amount' => round($salesTarget, 2),
            'achieved_amount' => round($achieved, 2),
            'sales_percentage' => $salesTarget > 0 ? round(($achieved / $salesTarget) * 100, 2) : null,
            'receivable_amount' => round($collectionTarget, 2),
            'received_amount' => round($collected, 2),
            'collection_percentage' => $collectionTarget > 0 ? round(($collected / $collectionTarget) * 100, 2) : null,
            'scope' => $userId ? 'member' : 'team',
            'user_id' => $userId,
        ];
    }

    public function achieved(int $organizationId, int|Collection $userIds, string $from, string $to): float
    {
        $ids = $userIds instanceof Collection ? $userIds : collect([$userIds]);
        if ($ids->isEmpty()) return 0.0;

        return (float) Deal::query()
            ->where('organization_id', $organizationId)
            ->whereIn('assigned_to', $ids)
            ->where('status', 'won')
            ->whereDate('closed_at', '>=', $from)
            ->whereDate('closed_at', '<=', $to)
            ->sum('value');
    }

    public function received(int $organizationId, int|Collection $userIds, string $from, string $to): float
    {
        $ids = $userIds instanceof Collection ? $userIds : collect([$userIds]);
        if ($ids->isEmpty()) return 0.0;

        return (float) DealPayment::query()
            ->where('organization_id', $organizationId)
            ->whereHas('deal', fn (Builder $query) => $query->whereIn('assigned_to', $ids))
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->sum('amount');
    }

    public function salesDetailsQuery(int $organizationId, ?int $userId, string $from, string $to): Builder
    {
        $ids = $userId ? collect([$userId]) : $this->eligibleUserIds($organizationId);

        return Deal::query()
            ->where('organization_id', $organizationId)
            ->whereIn('assigned_to', $ids)
            ->where('status', 'won')
            ->whereDate('closed_at', '>=', $from)
            ->whereDate('closed_at', '<=', $to);
    }

    public function collectionDetailsQuery(int $organizationId, ?int $userId, ?string $from = null, ?string $to = null): Builder
    {
        $ids = $userId ? collect([$userId]) : $this->eligibleUserIds($organizationId);

        return DealPayment::query()
            ->where('deal_payments.organization_id', $organizationId)
            ->whereHas('deal', fn (Builder $query) => $query->whereIn('assigned_to', $ids))
            ->when($from, fn (Builder $query) => $query->whereDate('payment_date', '>=', $from))
            ->when($to, fn (Builder $query) => $query->whereDate('payment_date', '<=', $to));
    }

    public function activeSalesDetailsQuery(int $organizationId, ?int $userId, Carbon $at): Builder
    {
        $targets = $userId
            ? collect(array_filter([$this->activeTarget($organizationId, $userId, $at)]))
            : $this->targetsForDate($organizationId, $at);

        return Deal::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'won')
            ->when($targets->isEmpty(), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($targets->isNotEmpty(), function (Builder $query) use ($targets) {
                $query->where(function (Builder $ranges) use ($targets) {
                    foreach ($targets as $target) {
                        $ranges->orWhere(function (Builder $range) use ($target) {
                            $range->where('assigned_to', $target->user_id)
                                ->whereDate('closed_at', '>=', Carbon::parse($target->period_start)->toDateString())
                                ->whereDate('closed_at', '<=', $this->periodEnd($target)->toDateString());
                        });
                    }
                });
            });
    }

    public function activeCollectionDetailsQuery(int $organizationId, ?int $userId, Carbon $at): Builder
    {
        $targets = $userId
            ? collect(array_filter([$this->activeTarget($organizationId, $userId, $at)]))
            : $this->targetsForDate($organizationId, $at);

        return DealPayment::query()
            ->where('deal_payments.organization_id', $organizationId)
            ->when($targets->isEmpty(), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($targets->isNotEmpty(), function (Builder $query) use ($targets) {
                $query->where(function (Builder $ranges) use ($targets) {
                    foreach ($targets as $target) {
                        $ranges->orWhere(function (Builder $range) use ($target) {
                            $range->whereDate('payment_date', '>=', Carbon::parse($target->period_start)->toDateString())
                                ->whereDate('payment_date', '<=', $this->periodEnd($target)->toDateString())
                                ->whereHas('deal', fn (Builder $deal) => $deal->where('assigned_to', $target->user_id));
                        });
                    }
                });
            });
    }

    public function workingDayProgress(SalesTarget $target, ?Carbon $at = null): float
    {
        $at ??= now();
        $start = Carbon::parse($target->period_start)->startOfDay();
        $end = $this->periodEnd($target);
        if ($at->lt($start)) return 0.0;

        $total = max(1, $this->workingDays($start, $end));
        $elapsed = $this->workingDays($start, $at->copy()->min($end));
        return min(100, round(($elapsed / $total) * 100, 2));
    }

    private function workingDays(Carbon $from, Carbon $to): int
    {
        $days = 0;
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if (!$day->isWeekend()) $days++;
        }
        return $days;
    }
}
