<?php

namespace App\Services\Billing;

use InvalidArgumentException;

class BillingCalculationService
{
    /**
     * Synq-compatible line calculation using integer minor units. Quantity and
     * percentage inputs are scaled to four decimals; money is rounded half-up
     * at every monetary boundary.
     */
    public function calculate(array $items, bool $interstate, bool $gstEnabled = true, int $roundingScale = 2): array
    {
        $totals = ['subtotal' => 0, 'discount_total' => 0, 'taxable_total' => 0, 'cgst_total' => 0, 'sgst_total' => 0, 'igst_total' => 0];
        $lines = [];

        foreach ($items as $index => $item) {
            $quantity = $this->scaled($item['quantity'] ?? 0, 4);
            $unitPrice = $this->money($item['unit_price'] ?? 0);
            if ($quantity <= 0 || $unitPrice < 0) {
                throw new InvalidArgumentException('Quantity must be positive and unit price cannot be negative.');
            }

            $base = intdiv(($quantity * $unitPrice) + 5000, 10000);
            $discountType = $item['discount_type'] ?? null;
            $discountInput = $item['discount_value'] ?? 0;
            $discount = match ($discountType) {
                'fixed' => min($base, $this->money($discountInput)),
                'percentage' => $this->percentageOf($base, $discountInput),
                null, '', 'none' => 0,
                default => throw new InvalidArgumentException('Discount type must be fixed or percentage.'),
            };
            if ($discountType === 'percentage' && ((float) $discountInput < 0 || (float) $discountInput > 100)) {
                throw new InvalidArgumentException('Percentage discounts must be between 0 and 100.');
            }

            $taxable = max(0, $base - $discount);
            $gstRate = $gstEnabled ? (float) ($item['gst_rate'] ?? 0) : 0.0;
            if ($gstRate < 0 || $gstRate > 100) {
                throw new InvalidArgumentException('GST rate must be between 0 and 100.');
            }
            $tax = $this->percentageOf($taxable, $gstRate);
            $igst = $interstate ? $tax : 0;
            $cgst = $interstate ? 0 : intdiv($tax, 2);
            $sgst = $interstate ? 0 : $tax - $cgst;
            $lineTotal = $taxable + $cgst + $sgst + $igst;

            $row = [
                'line_number' => $index + 1,
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => number_format($quantity / 10000, 4, '.', ''),
                'unit_price' => $this->decimal($unitPrice),
                'base_amount' => $this->decimal($base),
                'discount_amount' => $this->decimal($discount),
                'taxable_amount' => $this->decimal($taxable),
                'gst_rate' => number_format($gstRate, 4, '.', ''),
                'cgst_amount' => $this->decimal($cgst),
                'sgst_amount' => $this->decimal($sgst),
                'igst_amount' => $this->decimal($igst),
                'line_total' => $this->decimal($lineTotal),
            ];
            $lines[] = $row;
            $totals['subtotal'] += $base;
            $totals['discount_total'] += $discount;
            $totals['taxable_total'] += $taxable;
            $totals['cgst_total'] += $cgst;
            $totals['sgst_total'] += $sgst;
            $totals['igst_total'] += $igst;
        }

        $grand = $totals['taxable_total'] + $totals['cgst_total'] + $totals['sgst_total'] + $totals['igst_total'];
        $roundingAdjustment = 0;
        if ($roundingScale === 0) {
            $rounded = (int) (round($grand / 100, 0, PHP_ROUND_HALF_UP) * 100);
            $roundingAdjustment = $rounded - $grand;
            $grand = $rounded;
        }

        return [
            'items' => $lines,
            'totals' => array_map(fn (int $value) => $this->decimal($value), $totals) + [
                'rounding_adjustment' => $this->decimal($roundingAdjustment),
                'grand_total' => $this->decimal($grand),
            ],
        ];
    }

    private function money(mixed $value): int
    {
        return (int) round(((float) $value) * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function scaled(mixed $value, int $scale): int
    {
        return (int) round(((float) $value) * (10 ** $scale), 0, PHP_ROUND_HALF_UP);
    }

    private function percentageOf(int $amount, mixed $rate): int
    {
        return (int) round($amount * (float) $rate / 100, 0, PHP_ROUND_HALF_UP);
    }

    private function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
