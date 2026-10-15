<?php

namespace App\Services;

use App\Models\DealPayment;
use App\Models\RecurringBusiness;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RecurringRevenueService
{
    public function monthlyEquivalent(float $amount, string $frequency): float
    {
        return round(match ($frequency) {
            'MONTHLY' => $amount,
            'QUARTERLY' => $amount / 3,
            'HALF_YEARLY' => $amount / 6,
            'YEARLY' => $amount / 12,
            default => 0,
        }, 2);
    }

    public function nextBillingDate(string|CarbonInterface $start, string $frequency, int $cycles = 1): Carbon
    {
        $date = Carbon::parse($start);

        return match ($frequency) {
            'MONTHLY' => $date->addMonthsNoOverflow($cycles),
            'QUARTERLY' => $date->addMonthsNoOverflow(3 * $cycles),
            'HALF_YEARLY' => $date->addMonthsNoOverflow(6 * $cycles),
            'YEARLY' => $date->addYears($cycles),
            default => $date,
        };
    }

    public function cyclesThrough(RecurringBusiness $business, CarbonInterface $through): int
    {
        $start = $business->start_date->copy()->startOfDay();
        $limit = Carbon::instance($through)->startOfDay();
        if ($business->end_date && $business->end_date->lt($limit)) {
            $limit = $business->end_date->copy()->startOfDay();
        }
        $deletedAt = $this->deletedDealAt($business);
        if ($deletedAt && $deletedAt->lt($limit)) {
            $limit = $deletedAt->startOfDay();
        }
        if ($limit->lt($start)) {
            return 0;
        }

        $cycles = 0;
        $cursor = $start->copy();
        $cap = $business->billing_cycles;
        while ($cursor->lte($limit) && ($cap === null || $cycles < $cap)) {
            $cycles++;
            $cursor = $this->nextBillingDate($cursor, $business->frequency);
        }

        return $cycles;
    }

    public function dueAmount(RecurringBusiness $business, CarbonInterface $through): float
    {
        return round($this->cyclesThrough($business, $through) * (float) $business->amount, 2);
    }

    public function upcomingBillingDate(RecurringBusiness $business, CarbonInterface $asOf): ?Carbon
    {
        if ($business->status !== 'ACTIVE' || $this->dealWasDeleted($business)) {
            return null;
        }
        $next = $business->next_billing_date?->copy()
            ?? $this->nextBillingDate($business->start_date, $business->frequency);
        $steps = 1;
        while ($next->lte($asOf)) {
            $steps++;
            if ($business->billing_cycles !== null && $steps >= $business->billing_cycles) {
                return null;
            }
            $next = $this->nextBillingDate($next, $business->frequency);
        }
        if ($business->end_date && $next->gt($business->end_date)) {
            return null;
        }

        return $next;
    }

    public function collectedAmount(RecurringBusiness $business, ?CarbonInterface $from = null, ?CarbonInterface $to = null): float
    {
        $deal = $business->relationLoaded('deal') ? $business->getRelation('deal') : null;
        if ($deal?->relationLoaded('payments')) {
            $payments = $deal->payments;
            if ($from) {
                $payments = $payments->filter(fn ($payment) => $payment->payment_date->gte($from));
            }
            if ($to) {
                $payments = $payments->filter(fn ($payment) => $payment->payment_date->lte($to));
            }

            return round((float) $payments->sum('amount'), 2);
        }

        if (! $business->deal_id) {
            return 0.0;
        }

        $query = DealPayment::query()
            ->where('organization_id', $business->organization_id)
            ->where('deal_id', $business->deal_id);
        if ($from) {
            $query->where('payment_date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->where('payment_date', '<=', $to->toDateString());
        }

        return round((float) $query->sum('amount'), 2);
    }

    public function dealWasDeleted(RecurringBusiness $business): bool
    {
        return $this->deletedDealAt($business) !== null;
    }

    /** @return array<string, float|int> */
    public function summary(Collection $businesses, CarbonInterface $asOf): array
    {
        $monthStart = Carbon::instance($asOf)->copy()->startOfMonth();
        $monthEnd = Carbon::instance($asOf)->copy()->endOfMonth();
        $active = $businesses->filter(fn (RecurringBusiness $b) => $b->status === 'ACTIVE'
            && ! $this->dealWasDeleted($b)
            && $b->start_date->lte($asOf)
            && (! $b->end_date || $b->end_date->gte($asOf)));
        $mrr = $active->sum(fn (RecurringBusiness $b) => $this->monthlyEquivalent((float) $b->amount, $b->frequency));
        $collected = $active->sum(fn (RecurringBusiness $b) => $this->collectedAmount($b, $monthStart, $monthEnd));
        $expected = $active->sum(function (RecurringBusiness $b) use ($monthStart, $monthEnd) {
            return max(0, $this->dueAmount($b, $monthEnd) - $this->dueAmount($b, $monthStart->copy()->subDay()));
        });
        $overdue = $active->sum(function (RecurringBusiness $b) use ($asOf) {
            return max(0, $this->dueAmount($b, $asOf) - $this->collectedAmount($b));
        });

        return [
            'active_count' => $active->count(),
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'expected_this_month' => round($expected, 2),
            'collected_this_month' => round($collected, 2),
            'overdue' => round($overdue, 2),
            'upcoming_renewals' => $active->filter(fn (RecurringBusiness $b) => $b->end_date && $b->end_date->between($asOf, Carbon::instance($asOf)->copy()->addDays(30)))->count(),
            'cancelled_or_expired' => $businesses->filter(fn (RecurringBusiness $b) => in_array($b->status, ['CANCELLED', 'EXPIRED', 'COMPLETED'], true)
                || $this->dealWasDeleted($b)
                || ($b->end_date && $b->end_date->lt($asOf)))->count(),
        ];
    }

    private function deletedDealAt(RecurringBusiness $business): ?Carbon
    {
        if (! $business->deal_id) {
            return null;
        }

        $deal = $business->relationLoaded('deal')
            ? $business->getRelation('deal')
            : $business->deal;

        return $deal?->trashed() ? Carbon::instance($deal->deleted_at) : null;
    }
}
