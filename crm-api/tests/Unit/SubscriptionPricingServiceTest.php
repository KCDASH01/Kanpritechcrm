<?php

namespace Tests\Unit;

use App\Services\SubscriptionPricingService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SubscriptionPricingServiceTest extends TestCase
{
    public function test_approved_prices_and_seven_day_first_billing_date(): void
    {
        $service = new SubscriptionPricingService;
        $now = CarbonImmutable::parse('2026-10-29 10:00:00', 'Asia/Kolkata');

        self::assertSame(79900, $service->quote('growth', 'monthly', 'INR', $now)['amount_minor']);
        self::assertSame(799000, $service->quote('growth', 'yearly', 'INR', $now)['amount_minor']);
        self::assertSame(199900, $service->quote('business', 'monthly', 'INR', $now)['amount_minor']);
        self::assertSame(1999000, $service->quote('business', 'yearly', 'INR', $now)['amount_minor']);
        self::assertSame(1500, $service->quote('growth', 'monthly', 'USD', $now)['amount_minor']);
        self::assertSame(3900, $service->quote('business', 'monthly', 'USD', $now)['amount_minor']);
        self::assertTrue($service->quote('growth', 'monthly', 'INR', $now)['tax_exclusive']);
        self::assertSame('2026-11-05 10:00:00', $service->quote('growth', 'monthly', 'INR', $now)['first_billing_at']->format('Y-m-d H:i:s'));
    }
}
