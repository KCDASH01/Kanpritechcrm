<?php

namespace Tests\Unit;

use App\Services\RazorpaySubscriptionGateway;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RazorpayGatewaySafetyTest extends TestCase
{
    public function test_test_mode_rejects_a_live_key_before_any_provider_request(): void
    {
        config()->set('services.razorpay.key_id', 'rzp_live_misconfigured');
        config()->set('services.razorpay.key_secret', 'not-a-real-secret');
        config()->set('services.razorpay.test_mode', true);
        config()->set('services.razorpay.live_subscriptions_enabled', false);

        $this->expectException(ValidationException::class);
        app(RazorpaySubscriptionGateway::class)->createOrder(['amount' => 100, 'currency' => 'INR']);
    }

    public function test_live_provider_requests_remain_disabled_without_explicit_approval(): void
    {
        config()->set('services.razorpay.key_id', 'rzp_live_disabled');
        config()->set('services.razorpay.key_secret', 'not-a-real-secret');
        config()->set('services.razorpay.test_mode', false);
        config()->set('services.razorpay.live_subscriptions_enabled', false);

        $this->expectException(ValidationException::class);
        app(RazorpaySubscriptionGateway::class)->createSubscription(['plan_id' => 'plan_disabled']);
    }
}
