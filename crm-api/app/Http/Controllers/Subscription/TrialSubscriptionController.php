<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Services\PromotionService;
use App\Services\SubscriptionLifecycleService;
use App\Services\SubscriptionPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrialSubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly SubscriptionPricingService $pricing,
        private readonly PromotionService $promotions,
    ) {}

    public function pricing(): JsonResponse
    {
        return response()->json([
            'data' => $this->pricing->publicPlans(),
            'trial_days' => 7,
            'tax_disclosure' => 'Prices exclude applicable taxes.',
            'promotions_enabled' => (bool) config('services.promotions.enabled', false),
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'in:growth,business'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'currency' => ['required', 'in:INR,USD'],
        ]);

        $quote = $this->pricing->quote($data['plan'], $data['billing_cycle'], $data['currency']);
        if ($data['currency'] === 'USD' && ! config('services.razorpay.usd_recurring_enabled')) {
            return response()->json([
                'message' => 'USD recurring payments are not yet enabled for this merchant account.',
                'errors' => ['currency' => ['Razorpay merchant eligibility must be confirmed first.']],
            ], 422);
        }
        $quote['first_billing_at'] = $quote['first_billing_at']->toIso8601String();

        return response()->json(['data' => $quote]);
    }

    public function authorizePaymentMethod(Request $request): JsonResponse
    {
        $result = $this->lifecycle->startAuthorization($request->user()->organization, $request->user());

        return response()->json(['data' => $result]);
    }

    public function select(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'in:growth,business'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'currency' => ['required', 'in:INR,USD'],
            'coupon_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        if ($data['currency'] === 'USD' && ! config('services.razorpay.usd_recurring_enabled')) {
            return response()->json(['message' => 'USD recurring payments require confirmed Razorpay merchant eligibility.'], 422);
        }

        $subscription = $this->lifecycle->selectTrial(
            $request->user()->organization,
            $request->user(),
            $data['plan'],
            $data['billing_cycle'],
            $data['currency'],
            $data['coupon_code'] ?? null,
        );

        return response()->json(['data' => new SubscriptionResource($subscription)]);
    }

    public function validatePromotion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'in:growth,business'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'currency' => ['required', 'in:INR,USD'],
            'coupon_code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        if ($data['currency'] === 'USD' && ! config('services.razorpay.usd_recurring_enabled')) {
            return response()->json(['message' => 'USD recurring payments require confirmed Razorpay merchant eligibility.'], 422);
        }
        $price = $this->pricing->quote($data['plan'], $data['billing_cycle'], $data['currency']);
        $promotion = $this->promotions->quote(
            $request->user()->organization,
            $data['plan'],
            $data['billing_cycle'],
            $data['currency'],
            $price['amount_minor'],
            $data['coupon_code'],
        );

        return response()->json(['data' => $promotion]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'razorpay_subscription_id' => ['required', 'string', 'max:191'],
            'razorpay_payment_id' => ['required', 'string', 'max:191'],
            'razorpay_signature' => ['required', 'string', 'max:512'],
        ]);
        $subscription = $this->lifecycle->verifyAuthorization(
            $request->user()->organization,
            $request->user(),
            $data['razorpay_subscription_id'],
            $data['razorpay_payment_id'],
            $data['razorpay_signature'],
        );

        return response()->json([
            'data' => new SubscriptionResource($subscription),
            'message' => 'Your 7-day trial is active.',
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $subscriptionIds = Subscription::where('organization_id', $request->user()->organization_id)->pluck('id');
        $rows = SubscriptionTransaction::where('organization_id', $request->user()->organization_id)
            ->whereIn('subscription_id', $subscriptionIds)
            ->latest('occurred_at')
            ->paginate(25);

        return response()->json(['data' => $rows->items(), 'meta' => ['total' => $rows->total()]]);
    }
}
