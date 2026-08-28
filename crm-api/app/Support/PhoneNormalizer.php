<?php

namespace App\Support;

class PhoneNormalizer
{
    /**
     * Canonical form for duplicate checks: 91 + local digits (after stripping country/trunk codes).
     * Treats +91784758965 and 784758965 as the same number.
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
            $digits = ltrim($digits, '0');
        }

        if ($digits === '') {
            return null;
        }

        // If extra digits remain (extensions, bad paste), keep the last 10 as the mobile number.
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        return '91' . $digits;
    }
}
