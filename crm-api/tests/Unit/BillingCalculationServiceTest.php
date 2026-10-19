<?php

namespace Tests\Unit;

use App\Services\Billing\BillingCalculationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BillingCalculationServiceTest extends TestCase
{
    public function test_intrastate_gst_and_percentage_discount_match_synq_rules(): void
    {
        $result = (new BillingCalculationService)->calculate([[
            'description' => 'Development', 'quantity' => 2, 'unit_price' => 1000,
            'discount_type' => 'percentage', 'discount_value' => 10, 'gst_rate' => 18,
        ]], false);

        self::assertSame('2000.00', $result['totals']['subtotal']);
        self::assertSame('200.00', $result['totals']['discount_total']);
        self::assertSame('1800.00', $result['totals']['taxable_total']);
        self::assertSame('162.00', $result['totals']['cgst_total']);
        self::assertSame('162.00', $result['totals']['sgst_total']);
        self::assertSame('2124.00', $result['totals']['grand_total']);
    }

    public function test_interstate_and_gst_disabled_calculations_are_fixed_precision(): void
    {
        $service = new BillingCalculationService;
        $interstate = $service->calculate([['description' => 'Support', 'quantity' => 1.5, 'unit_price' => 99.99, 'discount_type' => 'fixed', 'discount_value' => 9.99, 'gst_rate' => 18]], true);
        self::assertSame('140.00', $interstate['totals']['taxable_total']);
        self::assertSame('25.20', $interstate['totals']['igst_total']);
        self::assertSame('165.20', $interstate['totals']['grand_total']);

        $withoutGst = $service->calculate([['description' => 'Export', 'quantity' => 1, 'unit_price' => 100, 'gst_rate' => 18]], true, false);
        self::assertSame('0.00', $withoutGst['totals']['igst_total']);
        self::assertSame('100.00', $withoutGst['totals']['grand_total']);
    }

    public function test_invalid_percentage_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BillingCalculationService)->calculate([['description' => 'Invalid', 'quantity' => 1, 'unit_price' => 100, 'discount_type' => 'percentage', 'discount_value' => 101]], false);
    }
}
