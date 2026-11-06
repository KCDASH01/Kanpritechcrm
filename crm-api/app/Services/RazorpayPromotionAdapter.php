<?php

namespace App\Services;

use App\Models\PromotionRedemption;
use Illuminate\Validation\ValidationException;

class RazorpayPromotionAdapter
{
    public function applyToSubscriptionPayload(array $payload, ?PromotionRedemption $redemption): array
    {
        if (! $redemption) {
            return $payload;
        }
        if (! config('services.promotions.enabled') || ! config('services.promotions.razorpay_subscription_offers_enabled')) {
            throw ValidationException::withMessages([
                'coupon_code' => 'This coupon is configured locally but Razorpay subscription offers are not approved for checkout.',
            ]);
        }
        if (! $redemption->gateway_offer_id) {
            throw ValidationException::withMessages([
                'coupon_code' => 'This coupon is not mapped to an approved Razorpay offer for the selected plan.',
            ]);
        }

        $payload['offer_id'] = $redemption->gateway_offer_id;
        $payload['notes']['promotion_redemption_id'] = (string) $redemption->id;
        $payload['notes']['promotion_code'] = (string) $redemption->campaign?->code;

        return $payload;
    }
}
