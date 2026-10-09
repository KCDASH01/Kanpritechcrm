<?php

namespace Tests\Unit;

use App\Models\Deal;
use App\Models\RecurringBusiness;
use App\Services\DealStatusService;
use App\Services\RecurringRevenueService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecurringRevenueServiceTest extends TestCase
{
    private RecurringRevenueService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RecurringRevenueService;
    }

    public static function frequencyCases(): array
    {
        return [['MONTHLY', 1200.0], ['QUARTERLY', 400.0], ['HALF_YEARLY', 200.0], ['YEARLY', 100.0]];
    }

    #[DataProvider('frequencyCases')]
    public function test_mrr_normalizes_each_supported_frequency(string $frequency, float $expected): void
    {
        self::assertSame($expected, $this->service->monthlyEquivalent(1200, $frequency));
    }

    public function test_next_billing_date_respects_frequency(): void
    {
        self::assertSame('2027-02-28', $this->service->nextBillingDate('2027-01-31', 'MONTHLY')->toDateString());
        self::assertSame('2027-07-31', $this->service->nextBillingDate('2027-01-31', 'HALF_YEARLY')->toDateString());
    }

    public function test_revenue_is_not_due_before_contract_start(): void
    {
        $business = new RecurringBusiness(['amount' => 25000, 'frequency' => 'MONTHLY', 'start_date' => '2027-01-01']);
        self::assertSame(0.0, $this->service->dueAmount($business, Carbon::parse('2026-12-31')));
    }

    public function test_due_revenue_counts_only_elapsed_cycles(): void
    {
        $business = new RecurringBusiness(['amount' => 25000, 'frequency' => 'MONTHLY', 'start_date' => '2027-01-01']);
        self::assertSame(75000.0, $this->service->dueAmount($business, Carbon::parse('2027-03-15')));
    }

    public function test_fixed_end_date_prevents_future_contract_value_becoming_due(): void
    {
        $business = new RecurringBusiness(['amount' => 25000, 'frequency' => 'MONTHLY', 'start_date' => '2027-01-01', 'end_date' => '2027-03-31']);
        self::assertSame(75000.0, $this->service->dueAmount($business, Carbon::parse('2028-01-01')));
    }

    public function test_billing_cycle_cap_prevents_over_recognition(): void
    {
        $business = new RecurringBusiness(['amount' => 25000, 'frequency' => 'MONTHLY', 'start_date' => '2027-01-01', 'billing_cycles' => 2]);
        self::assertSame(50000.0, $this->service->dueAmount($business, Carbon::parse('2028-01-01')));
    }

    public function test_next_billing_advances_without_recognizing_future_revenue(): void
    {
        $business = new RecurringBusiness(['amount' => 25000, 'frequency' => 'MONTHLY', 'start_date' => '2027-01-01', 'next_billing_date' => '2027-02-01', 'status' => 'ACTIVE']);
        self::assertSame('2027-04-01', $this->service->upcomingBillingDate($business, Carbon::parse('2027-03-15'))?->toDateString());
    }

    public function test_recurring_deal_never_auto_wins_after_a_cycle_payment(): void
    {
        $deal = new Deal(['status' => 'open', 'business_type' => 'RECURRING', 'value' => 25000]);
        self::assertFalse((new DealStatusService)->markWonIfFullyPaid($deal));
    }
}
