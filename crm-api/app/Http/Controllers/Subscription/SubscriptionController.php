<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptionService) {}

    // ── GET /api/subscription ─────────────────────────────────────────────────

    public function show(Request $request): JsonResponse
    {
        $org = $request->user()->organization;
        $sub = $org->activeSubscription();

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

        $razorpayKeyId     = config('services.razorpay.key_id');
        $razorpayKeySecret = config('services.razorpay.key_secret');

        $response = Http::withBasicAuth($razorpayKeyId, $razorpayKeySecret)
            ->withoutVerifying()
            ->post('https://api.razorpay.com/v1/orders', [
                'amount'   => (int) $amountPaise,
                'currency' => 'INR',
                'receipt'  => 'enterprise_' . $org->id . '_' . time(),
                'notes'    => [
                    'organization_id' => $org->id,
                    'plan'            => 'enterprise',
                    'cycle'           => $data['cycle'],
                ],
            ]);

        if (! $response->successful()) {
            return response()->json(['message' => 'Failed to create payment order. Please try again.'], 502);
        }

        $order = $response->json();

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
            $expected = hash_hmac(
                'sha256',
                $data['razorpay_order_id'] . '|' . $data['razorpay_payment_id'],
                config('services.razorpay.key_secret')
            );

            if (! hash_equals($expected, $data['razorpay_signature'])) {
                return response()->json(['message' => 'Payment verification failed. Invalid signature.'], 422);
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

        // ── Non-SSO path: admin-initiated plan change ─────────────────────────
        $data = $request->validate([
            'plan'       => ['required', 'in:free,business,enterprise'],
            'gateway'    => ['nullable', 'string'],
            'gateway_id' => ['nullable', 'string'],
            'amount'     => ['nullable', 'numeric'],
            'currency'   => ['nullable', 'string', 'size:3'],
            'end_date'   => ['nullable', 'date'],
        ]);

        $sub = $this->subscriptionService->changePlan(
            org:      $org,
            plan:     $data['plan'],
            gateway:  $data['gateway'] ?? null,
            gatewayId:$data['gateway_id'] ?? null,
            amount:   $data['amount'] ?? null,
            currency: $data['currency'] ?? 'INR',
            endDate:  isset($data['end_date']) ? \Carbon\Carbon::parse($data['end_date']) : null,
        );

        // Record the payment when a paid plan is activated with an amount
        if ($data['plan'] !== 'free' && ! empty($data['amount'])) {
            SubscriptionPayment::create([
                'organization_id'    => $org->id,
                'subscription_id'    => $sub->id,
                'user_id'            => $user->id,
                'type'               => 'plan_upgrade',
                'gateway'            => $data['gateway'] ?? 'razorpay',
                'gateway_payment_id' => $data['gateway_id'] ?? null,
                'amount'             => $data['amount'],
                'currency'           => $data['currency'] ?? 'INR',
                'status'             => 'completed',
                'valid_until'        => $sub->end_date?->toDateString(),
                'description'        => ucfirst($data['plan']) . ' Plan Upgrade',
            ]);
        }

        return response()->json([
            'data'    => new SubscriptionResource($sub->fresh()),
            'message' => 'Subscription updated successfully.',
        ]);
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

        $prices = [
            'business'   => ['monthly' => 999,  'yearly' => 9999],
            'enterprise' => ['monthly' => 1999, 'yearly' => 19999],
        ];

        $data = $request->validate([
            'plan'               => ['required', 'in:business,enterprise'],
            'cycle'              => ['required', 'in:monthly,yearly'],
            'bill_to_name'       => ['required', 'string', 'max:191'],
            'bill_to_email'      => ['required', 'email',  'max:191'],
            'bill_to_company'    => ['nullable', 'string', 'max:191'],
            'bill_to_gstin'      => ['nullable', 'string', 'max:15'],
            'bill_to_address'    => ['required', 'string', 'max:500'],
            'bill_to_city'       => ['required', 'string', 'max:191'],
            'bill_to_pincode'    => ['required', 'string', 'max:10'],
            'bill_to_state'      => ['required', 'string', 'max:191'],
            'bill_to_state_code' => ['required', 'string', 'max:10'],
            'bill_to_country'    => ['required', 'string', 'max:191'],
        ]);

        $amount      = $prices[$data['plan']][$data['cycle']];
        $amountPaise = $amount * 100;
        $endDate     = $data['cycle'] === 'yearly' ? now()->addYear() : now()->addMonth();

        $keyId     = config('services.razorpay.key_id');
        $keySecret = config('services.razorpay.key_secret');

        $response = Http::withBasicAuth($keyId, $keySecret)
            ->withoutVerifying()
            ->post('https://api.razorpay.com/v1/orders', [
                'amount'   => (int) $amountPaise,
                'currency' => 'INR',
                'receipt'  => 'crm_' . $org->id . '_' . $data['plan'] . '_' . time(),
                'notes'    => [
                    'organization_id' => $org->id,
                    'plan'            => $data['plan'],
                    'cycle'           => $data['cycle'],
                ],
            ]);

        if (! $response->successful()) {
            Log::error('SubscriptionController::subscribeOrder — Razorpay failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'org_id' => $org->id,
            ]);
            return response()->json(['message' => 'Failed to create payment order. Please try again.'], 502);
        }

        $order = $response->json();

        // Cache billing address + plan info against the order ID
        $billingKeys = [
            'bill_to_name', 'bill_to_email', 'bill_to_company', 'bill_to_gstin',
            'bill_to_address', 'bill_to_city', 'bill_to_pincode',
            'bill_to_state', 'bill_to_state_code', 'bill_to_country',
        ];
        Cache::put("crm_billing_meta_{$order['id']}", array_merge(
            array_intersect_key($data, array_flip($billingKeys)),
            ['plan' => $data['plan'], 'cycle' => $data['cycle'], 'end_date' => $endDate->toDateString()]
        ), now()->addDays(7));

        // Create a pending payment record
        $sub = $org->activeSubscription();
        SubscriptionPayment::create([
            'organization_id'  => $org->id,
            'subscription_id'  => $sub?->id,
            'user_id'          => $user->id,
            'type'             => 'plan_upgrade',
            'gateway'          => 'razorpay',
            'gateway_order_id' => $order['id'],
            'amount'           => $amount,
            'currency'         => 'INR',
            'status'           => 'pending',
            'valid_until'      => $endDate->toDateString(),
            'description'      => ucfirst($data['plan']) . ' Plan — ' . ucfirst($data['cycle']),
        ]);

        return response()->json([
            'data' => [
                'order_id' => $order['id'],
                'amount'   => $amountPaise,
                'currency' => 'INR',
                'key'      => $keyId,
                'end_date' => $endDate->toDateString(),
                'plan'     => $data['plan'],
                'cycle'    => $data['cycle'],
            ],
        ]);
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

        $data = $request->validate([
            'razorpay_order_id'   => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature'  => ['required', 'string'],
        ]);

        // ── HMAC-SHA256 verification ──────────────────────────────────────────
        $expected = hash_hmac(
            'sha256',
            $data['razorpay_order_id'] . '|' . $data['razorpay_payment_id'],
            config('services.razorpay.key_secret')
        );

        if (! hash_equals($expected, $data['razorpay_signature'])) {
            return response()->json(['message' => 'Payment verification failed. Invalid signature.'], 422);
        }

        // ── Retrieve cached meta ──────────────────────────────────────────────
        $meta    = Cache::pull("crm_billing_meta_{$data['razorpay_order_id']}") ?? [];
        $plan    = $meta['plan']     ?? null;
        $endDate = $meta['end_date'] ?? null;

        if (! $plan || ! $endDate) {
            return response()->json(['message' => 'Order context not found. Please contact support.'], 422);
        }

        // ── Activate subscription ─────────────────────────────────────────────
        $sub = $this->subscriptionService->changePlan(
            org:      $org,
            plan:     $plan,
            gateway:  'razorpay',
            gatewayId:$data['razorpay_payment_id'],
            amount:   SubscriptionPayment::where('gateway_order_id', $data['razorpay_order_id'])->value('amount'),
            currency: 'INR',
            endDate:  \Carbon\Carbon::parse($endDate),
        );

        // ── Mark pending payment record as completed ──────────────────────────
        SubscriptionPayment::where('gateway_order_id', $data['razorpay_order_id'])
            ->where('organization_id', $org->id)
            ->update([
                'status'             => 'completed',
                'gateway_payment_id' => $data['razorpay_payment_id'],
                'subscription_id'    => $sub->id,
            ]);

        return response()->json([
            'data'    => new SubscriptionResource($sub->fresh()),
            'message' => 'Subscription activated successfully.',
        ]);
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

        $sub = $this->subscriptionService->cancel($org, $user);

        return response()->json([
            'data'    => new SubscriptionResource($sub->fresh()),
            'message' => 'Subscription cancelled. You have been moved to the free plan.',
        ]);
    }
}
