<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SubscriptionPricingService
{
    private const PRICES = [
        'growth' => [
            'monthly' => ['INR' => 79900, 'USD' => 1500],
            'yearly' => ['INR' => 799000, 'USD' => 15000],
        ],
        'business' => [
            'monthly' => ['INR' => 199900, 'USD' => 3900],
            'yearly' => ['INR' => 1999000, 'USD' => 39000],
        ],
    ];

    public function quote(string $plan, string $cycle, string $currency, ?CarbonImmutable $now = null): array
    {
        $currency = strtoupper($currency);
        $amount = self::PRICES[$plan][$cycle][$currency] ?? null;
        if ($amount === null) {
            throw ValidationException::withMessages(['plan' => 'The selected subscription price is unavailable.']);
        }

        $now ??= CarbonImmutable::now();
        $firstBillingAt = $now->addDays(7);

        return [
            'plan' => $plan,
            'billing_cycle' => $cycle,
            'currency' => $currency,
            'amount_minor' => $amount,
            'amount' => number_format($amount / 100, 2, '.', ''),
            'tax_exclusive' => true,
            'trial_days' => 7,
            'first_billing_at' => $firstBillingAt,
            'renews_automatically' => true,
        ];
    }

    public function publicPlans(): array
    {
        return [
            'growth' => self::PRICES['growth'],
            'business' => self::PRICES['business'],
            'enterprise' => ['custom' => true],
        ];
    }
}
