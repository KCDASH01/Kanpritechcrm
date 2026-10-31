<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class RazorpaySubscriptionGateway
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function createSubscription(array $payload): array
    {
        return $this->request()->post(self::BASE_URL.'/subscriptions', $payload)->throw()->json();
    }

    public function createOrder(array $payload): array
    {
        return $this->request()->post(self::BASE_URL.'/orders', $payload)->throw()->json();
    }

    public function fetchSubscription(string $id): array
    {
        return $this->request()->get(self::BASE_URL.'/subscriptions/'.rawurlencode($id))->throw()->json();
    }

    public function fetchPayment(string $id): array
    {
        return $this->request()->get(self::BASE_URL.'/payments/'.rawurlencode($id))->throw()->json();
    }

    public function cancelSubscription(string $id, bool $atCycleEnd): array
    {
        return $this->request()->post(
            self::BASE_URL.'/subscriptions/'.rawurlencode($id).'/cancel',
            ['cancel_at_cycle_end' => $atCycleEnd]
        )->throw()->json();
    }

    public function verifyCheckoutSignature(string $subscriptionId, string $paymentId, string $signature): bool
    {
        $secret = (string) config('services.razorpay.key_secret');
        if ($secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $paymentId.'|'.$subscriptionId, $secret), $signature);
    }

    public function verifyOrderSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = (string) config('services.razorpay.key_secret');
        if ($secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $orderId.'|'.$paymentId, $secret), $signature);
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = (string) config('services.razorpay.webhook_secret');
        if ($secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function planId(string $plan, string $cycle, string $currency): string
    {
        $id = (string) config("services.razorpay.plans.{$plan}.{$cycle}.{$currency}");
        if ($id === '') {
            throw ValidationException::withMessages([
                'currency' => "Recurring billing for {$plan} {$cycle} in {$currency} is not enabled yet.",
            ]);
        }

        return $id;
    }

    public function keyId(): string
    {
        return (string) config('services.razorpay.key_id');
    }

    private function request(): PendingRequest
    {
        $key = (string) config('services.razorpay.key_id');
        $secret = (string) config('services.razorpay.key_secret');
        if ($key === '' || $secret === '') {
            throw ValidationException::withMessages(['payment' => 'Razorpay Test Mode is not configured.']);
        }

        $testMode = (bool) config('services.razorpay.test_mode', true);
        $liveEnabled = (bool) config('services.razorpay.live_subscriptions_enabled', false);
        if ($testMode && ! str_starts_with($key, 'rzp_test_')) {
            throw ValidationException::withMessages(['payment' => 'Razorpay staging requires a Test Mode key.']);
        }
        if (! $testMode && ! $liveEnabled) {
            throw ValidationException::withMessages(['payment' => 'Live Razorpay requests are disabled.']);
        }

        // TLS verification deliberately remains enabled for all provider calls.
        return Http::withBasicAuth($key, $secret)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->retry(2, 200, throw: false);
    }
}
