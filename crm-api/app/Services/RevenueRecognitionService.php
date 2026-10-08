<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\RecurringBusiness;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RevenueRecognitionService
{
    public function __construct(private readonly RecurringRevenueService $recurringRevenue) {}

    /**
     * Main-dashboard recognition follows the existing closed-won rule for one-time
     * work, and recognizes recurring work only when a payment transaction exists.
     * The two sets are mutually exclusive, so a recurring contract is never counted twice.
     */
    public function breakdown(int $organizationId, ?int $assignedTo = null, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $oneTime = Deal::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'won')
            ->where(fn ($q) => $q->where('business_type', 'ONE_TIME')->orWhereNull('business_type'))
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->when($from, fn ($q) => $q->whereDate('closed_at', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('closed_at', '<=', $to->toDateString()))
            ->sum('value');

        $recurring = DealPayment::query()
            ->join('deals', 'deals.id', '=', 'deal_payments.deal_id')
            ->where('deal_payments.organization_id', $organizationId)
            ->whereNull('deals.deleted_at')
            ->where('deals.business_type', 'RECURRING')
            ->when($assignedTo, fn ($q) => $q->where('deals.assigned_to', $assignedTo))
            ->when($from, fn ($q) => $q->whereDate('deal_payments.payment_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('deal_payments.payment_date', '<=', $to->toDateString()))
            ->sum('deal_payments.amount');

        return [
            'one_time_revenue' => round((float) $oneTime, 2),
            'recurring_revenue' => round((float) $recurring, 2),
            'total_revenue' => round((float) $oneTime + (float) $recurring, 2),
        ];
    }

    /** @return Collection<int, array{date:string,revenue:float,one_time_revenue:float,recurring_revenue:float}> */
    public function dailyTrend(int $organizationId, ?int $assignedTo, Carbon $from, Carbon $to): Collection
    {
        $oneTime = Deal::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'won')
            ->where(fn ($q) => $q->where('business_type', 'ONE_TIME')->orWhereNull('business_type'))
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->whereBetween('closed_at', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('closed_at as date, SUM(value) as amount')
            ->groupBy('closed_at')
            ->pluck('amount', 'date');

        $recurring = DealPayment::query()
            ->join('deals', 'deals.id', '=', 'deal_payments.deal_id')
            ->where('deal_payments.organization_id', $organizationId)
            ->whereNull('deals.deleted_at')
            ->where('deals.business_type', 'RECURRING')
            ->when($assignedTo, fn ($q) => $q->where('deals.assigned_to', $assignedTo))
            ->whereBetween('deal_payments.payment_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('deal_payments.payment_date as date, SUM(deal_payments.amount) as amount')
            ->groupBy('deal_payments.payment_date')
            ->pluck('amount', 'date');

        return collect($oneTime->keys())->merge($recurring->keys())->unique()->sort()->values()->map(function ($date) use ($oneTime, $recurring) {
            $single = (float) ($oneTime[$date] ?? 0);
            $repeat = (float) ($recurring[$date] ?? 0);
            return [
                'date' => $date,
                'revenue' => round($single + $repeat, 2),
                'one_time_revenue' => round($single, 2),
                'recurring_revenue' => round($repeat, 2),
            ];
        });
    }

    /** @return array{total_collected:float,collected_this_month:float,receivable:float} */
    public function collectionSummary(int $organizationId, ?int $assignedTo, CarbonInterface $asOf): array
    {
        $payments = $this->collectionDetailsQuery($organizationId, $assignedTo);

        $monthStart = Carbon::instance($asOf)->copy()->startOfMonth()->toDateString();
        $monthEnd = Carbon::instance($asOf)->copy()->endOfMonth()->toDateString();

        return [
            'total_collected' => round((float) (clone $payments)->sum('amount'), 2),
            'collected_this_month' => round((float) (clone $payments)->whereBetween('payment_date', [$monthStart, $monthEnd])->sum('amount'), 2),
            'receivable' => $this->receivable($organizationId, $assignedTo, $asOf),
        ];
    }

    public function collectionDetailsQuery(int $organizationId, ?int $assignedTo, ?string $from = null, ?string $to = null): Builder
    {
        return DealPayment::query()
            ->where('deal_payments.organization_id', $organizationId)
            ->whereHas('deal', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->when($assignedTo, fn ($deal) => $deal->where('assigned_to', $assignedTo)))
            ->when($from, fn ($query) => $query->where('payment_date', '>=', $from))
            ->when($to, fn ($query) => $query->where('payment_date', '<=', $to));
    }

    public function receivable(int $organizationId, ?int $assignedTo, CarbonInterface $asOf): float
    {
        $oneTime = Deal::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'open')
            ->where(fn ($q) => $q->where('business_type', '!=', 'RECURRING')->orWhereNull('business_type'))
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->withSum('payments', 'amount')
            ->get()
            ->sum(fn (Deal $deal) => max(0, (float) $deal->value - (float) ($deal->payments_sum_amount ?? 0)));

        $recurring = RecurringBusiness::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['ACTIVE', 'PAUSED'])
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->with('deal.payments')
            ->get()
            ->sum(fn (RecurringBusiness $business) => max(
                0,
                $this->recurringRevenue->dueAmount($business, $asOf)
                    - $this->recurringRevenue->collectedAmount($business)
            ));

        return round((float) $oneTime + (float) $recurring, 2);
    }

    public function dealReceivable(Deal $deal, CarbonInterface $asOf): float
    {
        $collected = (float) ($deal->payments_sum_amount ?? $deal->payments()->sum('amount'));
        if ($deal->business_type === 'RECURRING' && $deal->recurringBusiness) {
            $due = $this->recurringRevenue->dueAmount($deal->recurringBusiness, $asOf);
            return round(max(0, $due - $collected), 2);
        }

        return round(max(0, (float) $deal->value - $collected), 2);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function receivableRows(int $organizationId, ?int $assignedTo, CarbonInterface $asOf): Collection
    {
        $oneTime = Deal::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'open')
            ->where(fn ($q) => $q->where('business_type', '!=', 'RECURRING')->orWhereNull('business_type'))
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->with(['client', 'lead', 'assignedTo'])
            ->withSum('payments', 'amount')
            ->get()
            ->map(function (Deal $deal) {
                $collected = (float) ($deal->payments_sum_amount ?? 0);
                return $this->receivableRow($deal, $collected, max(0, (float) $deal->value - $collected), null);
            })
            ->filter(fn (array $row) => $row['receivable'] > 0);

        $recurring = RecurringBusiness::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['ACTIVE', 'PAUSED'])
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->with(['deal.client', 'deal.lead', 'deal.assignedTo', 'deal.payments'])
            ->get()
            ->map(function (RecurringBusiness $business) use ($asOf) {
                $collected = $this->recurringRevenue->collectedAmount($business);
                $due = $this->recurringRevenue->dueAmount($business, $asOf);
                return $this->receivableRow($business->deal, $collected, max(0, $due - $collected), $business->next_billing_date?->toDateString());
            })
            ->filter(fn (array $row) => $row['receivable'] > 0);

        return $oneTime->concat($recurring)->sortByDesc('receivable')->values();
    }

    /** @return array<string, mixed> */
    private function receivableRow(Deal $deal, float $collected, float $receivable, ?string $dueDate): array
    {
        return [
            'deal_id' => $deal->id,
            'deal' => $deal->title,
            'client' => $deal->client?->company ?: $deal->client?->full_name,
            'lead' => $deal->lead?->full_name,
            'service' => $deal->service_type,
            'employee' => $deal->assignedTo?->name,
            'deal_value' => (float) $deal->value,
            'collected' => round($collected, 2),
            'receivable' => round($receivable, 2),
            'due_date' => $dueDate ?: $deal->expected_close_date?->toDateString(),
            'status' => $deal->status,
            'currency' => $deal->currency ?: 'INR',
        ];
    }
}
