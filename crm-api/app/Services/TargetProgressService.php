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
    public function period(string|null $month = null): array
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

    /** @return array<string, float|int|string|bool|null> */
    public function progress(int $organizationId, ?int $userId, string $periodStart, string $periodEnd): array
    {
        $eligibleIds = $userId ? collect([$userId]) : $this->eligibleUserIds($organizationId);

        $targetQuery = SalesTarget::query()
            ->where('organization_id', $organizationId)
            ->whereDate('period_start', $periodStart)
            ->whereIn('user_id', $eligibleIds);

        $targetCount = (clone $targetQuery)->count();
        $salesTarget = (float) (clone $targetQuery)->sum('target_amount');
        $collectionTarget = (float) (clone $targetQuery)->sum('receivable_amount');
        $achieved = $this->achieved($organizationId, $eligibleIds, $periodStart, $periodEnd);
        $collected = $this->received($organizationId, $eligibleIds, $periodStart, $periodEnd);

        return [
            'period_start' => $periodStart,
            'has_target' => $targetCount > 0,
            'target_count' => $targetCount,
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
            ->whereBetween('closed_at', [$from, $to])
            ->sum('value');
    }

    public function received(int $organizationId, int|Collection $userIds, string $from, string $to): float
    {
        $ids = $userIds instanceof Collection ? $userIds : collect([$userIds]);
        if ($ids->isEmpty()) return 0.0;

        return (float) DealPayment::query()
            ->where('organization_id', $organizationId)
            ->whereHas('deal', fn (Builder $query) => $query->whereIn('assigned_to', $ids))
            ->whereBetween('payment_date', [$from, $to])
            ->sum('amount');
    }

    public function salesDetailsQuery(int $organizationId, ?int $userId, string $from, string $to): Builder
    {
        $ids = $userId ? collect([$userId]) : $this->eligibleUserIds($organizationId);

        return Deal::query()
            ->where('organization_id', $organizationId)
            ->whereIn('assigned_to', $ids)
            ->where('status', 'won')
            ->whereBetween('closed_at', [$from, $to]);
    }

    public function collectionDetailsQuery(int $organizationId, ?int $userId, ?string $from = null, ?string $to = null): Builder
    {
        $ids = $userId ? collect([$userId]) : $this->eligibleUserIds($organizationId);

        return DealPayment::query()
            ->where('deal_payments.organization_id', $organizationId)
            ->whereHas('deal', fn (Builder $query) => $query->whereIn('assigned_to', $ids))
            ->when($from, fn ($query) => $query->where('payment_date', '>=', $from))
            ->when($to, fn ($query) => $query->where('payment_date', '<=', $to));
    }
}
