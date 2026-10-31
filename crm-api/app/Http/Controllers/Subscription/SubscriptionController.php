<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionService;
use App\Services\SubscriptionLifecycleService;
use App\Services\RazorpaySubscriptionGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly SubscriptionLifecycleService $subscriptionLifecycle,
        private readonly RazorpaySubscriptionGateway $razorpay,
    ) {}

    // ── GET /api/subscription ─────────────────────────────────────────────────

    public function show(Request $request): JsonResponse
    {
        $org = $request->user()->organization;
        $sub = $org->activeSubscription()
            ?? $org->subscriptions()->where('subscription_source', 'internal')->latest('id')->first();

        if (! $sub) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => new SubscriptionResource($sub->fresh())]);
    }

    // ── POST /api/subscription/upgrade/order ──────────────────────────────────
    // SSO Business users: create a Razorpay order for Enterprise upgrade.

    public function createUpgradeOrder(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        if (! $user->is_sso_user) {
            return response()->json(['message' => 'Only SSO users use this endpoint.'], 403);
        }

        $sub = $org->activeSubscription();

        if (! $sub || $sub->plan !== 'business') {
            return response()->json([
                'message' => 'Only Business plan users can upgrade to Enterprise here.',
            ], 403);
        }

        $data = $request->validate([
            'cycle' => ['required', 'in:monthly,yearly'],
        ]);

        $amount      = $data['cycle'] === 'yearly' ? 19999 : 1999;
        $amountPaise = $amount * 100;
        $endDate     = $data['cycle'] === 'yearly' ? now()->addYear() : now()->addMonth();

        $razorpayKeyId = $this->razorpay->keyId();
        try {
            $order = $this->razorpay->createOrder([
                'amount'   => (int) $amountPaise,
                'currency' => 'INR',
                'receipt'  => 'enterprise_' . $org->id . '_' . time(),
                'notes'    => [
                    'organization_id' => $org->id,
                    'plan'            => 'enterprise',
                    'cycle'           => $data['cycle'],
                ],
            ]);
        } catch (Throwable) {
            return response()->json(['message' => 'Failed to create payment order. Please try again.'], 502);
        }

        // Create a pending payment record immediately so it appears in history
        // even if the user closes Razorpay before completing payment.
        SubscriptionPayment::create([
            'organization_id'  => $org->id,
            'subscription_id'  => $sub->id,
            'user_id'          => $user->id,
            'type'             => 'plan_upgrade',
            'gateway'          => 'razorpay',
            'gateway_order_id' => $order['id'],
            'amount'           => $amount,
            'currency'         => 'INR',
            'status'           => 'pending',
            'valid_until'      => $endDate->toDateString(),
            'description'      => 'Enterprise Plan Upgrade',
        ]);

        return response()->json([
            'data' => [
                'order_id' => $order['id'],
                'amount'   => $amountPaise,
                'currency' => 'INR',
                'key'      => $razorpayKeyId,
                'cycle'    => $data['cycle'],
                'end_date' => $endDate->toDateString(),
            ],
        ]);
    }

    // ── POST /api/subscription/upgrade ────────────────────────────────────────

    public function upgrade(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        // ── SSO path: Business → Enterprise only, with Razorpay HMAC verification ──
        if ($user->is_sso_user) {
            $data = $request->validate([
                'plan'                => ['required', 'in:enterprise'],
                'razorpay_order_id'   => ['required', 'string'],
                'razorpay_payment_id' => ['required', 'string'],
                'razorpay_signature'  => ['required', 'string'],
                'amount'              => ['required', 'numeric'],
                'currency'            => ['nullable', 'string', 'size:3'],
                'end_date'            => ['required', 'date'],
            ]);

            // Verify HMAC-SHA256 signature
            if (! $this->razorpay->verifyOrderSignature(
                $data['razorpay_order_id'],
                $data['razorpay_payment_id'],
                $data['razorpay_signature']
            )) {
                return response()->json(['message' => 'Payment verification failed. Invalid signature.'], 422);
            }

            $payment = $this->razorpay->fetchPayment($data['razorpay_payment_id']);
            if (($payment['order_id'] ?? null) !== $data['razorpay_order_id']
                || ($payment['status'] ?? null) !== 'captured'
                || (int) ($payment['amount'] ?? 0) !== (int) round($data['amount'] * 100)
                || strtoupper((string) ($payment['currency'] ?? '')) !== strtoupper($data['currency'] ?? 'INR')) {
                return response()->json(['message' => 'Razorpay did not confirm a captured payment matching this upgrade.'], 422);
            }

            $sub = $this->subscriptionService->changePlan(
                org:      $org,
                plan:     'enterprise',
                gateway:  'razorpay',
                gatewayId:$data['razorpay_payment_id'],
                amount:   $data['amount'],
                currency: $data['currency'] ?? 'INR',
                endDate:  \Carbon\Carbon::parse($data['end_date']),
            );

            // Mark the pending payment record as completed
            SubscriptionPayment::where('gateway_order_id', $data['razorpay_order_id'])
                ->where('organization_id', $org->id)
                ->update([
                    'status'             => 'completed',
                    'gateway_payment_id' => $data['razorpay_payment_id'],
                    'subscription_id'    => $sub->id,
                ]);

            return response()->json([
                'data'    => new SubscriptionResource($sub->fresh()),
                'message' => 'Upgraded to Enterprise successfully.',
            ]);
        }

        return response()->json([
            'message' => 'Direct plan activation is disabled. Use the verified Razorpay Subscription trial flow.',
            'code' => 'VERIFIED_SUBSCRIPTION_REQUIRED',
        ], 410);
    }

    // ── POST /api/subscription/subscribe/order ────────────────────────────────
    // Non-SSO owners: create a Razorpay order for Business or Enterprise plan.

    public function subscribeOrder(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        if ($user->is_sso_user) {
            return response()->json(['message' => 'SSO users manage billing through the Lead Scraping App.'], 403);
        }

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the organization owner can subscribe.'], 403);
        }

        return response()->json([
            'message' => 'One-time plan orders are no longer accepted for new subscriptions. Start the recurring-payment trial flow.',
            'code' => 'USE_SUBSCRIPTION_TRIAL',
        ], 410);

    }

    // ── POST /api/subscription/subscribe/verify ───────────────────────────────
    // Verify Razorpay payment + activate subscription for non-SSO owners.

    public function subscribeVerify(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        if ($user->is_sso_user) {
            return response()->json(['message' => 'SSO users manage billing through the Lead Scraping App.'], 403);
        }

        return response()->json([
            'message' => 'One-time payment activation is disabled. Complete recurring payment authorization instead.',
            'code' => 'USE_SUBSCRIPTION_TRIAL',
        ], 410);

    }

    // ── POST /api/subscription/cancel ─────────────────────────────────────────

    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        if ($user->is_sso_user) {
            return response()->json([
                'message' => 'SSO users manage their subscription through the Lead Scraping App.',
            ], 403);
        }

        if (! in_array($user->role, ['owner', 'admin'], true)) {
            return response()->json(['message' => 'Only an owner or admin can change billing.'], 403);
        }

        $current = $org->activeSubscription();
        if ($current && ! $current->billing_cycle) {
            $sub = $this->subscriptionService->cancel($org, $user);

            return response()->json([
                'data' => new SubscriptionResource($sub->fresh()),
                'message' => 'Legacy subscription cancelled under the existing account policy.',
            ]);
        }

        $sub = $this->subscriptionLifecycle->cancel($org, $user);

        return response()->json([
            'data'    => new SubscriptionResource($sub->fresh()),
            'message' => $sub->status === 'trialing'
                ? 'Trial cancelled. Access remains available until the original trial expiry; no subscription fee will be charged.'
                : 'Cancellation scheduled for the end of the current paid billing period.',
        ]);
    }
}
