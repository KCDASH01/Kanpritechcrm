<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\RazorpaySubscriptionGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SeatController extends Controller
{
    private const SEAT_PRICE_MONTHLY = 299;   // ₹299 per seat per month
    private const MAX_SEATS_PER_PURCHASE = 10;

    public function __construct(private readonly RazorpaySubscriptionGateway $razorpay) {}

    // ── POST /api/subscription/seats/add ─────────────────────────────────────

    public function add(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        $sub = $org->activeSubscription();

        if (! $sub || ! in_array($sub->plan, ['business', 'enterprise'])) {
            return response()->json([
                'message' => 'Seat purchases are only available on Business or Enterprise plans.',
            ], 403);
        }

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:' . self::MAX_SEATS_PER_PURCHASE],
        ]);

        $quantity = $data['quantity'];

        // Pro-rate: charge ₹299 × remaining months until subscription end (min 1)
        $unitPrice       = self::SEAT_PRICE_MONTHLY;
        $remainingMonths = 1;
        if ($sub->end_date && $sub->end_date->isFuture()) {
            $remainingMonths = max(1, (int) now()->diffInMonths($sub->end_date));
        }
        $amount = $quantity * $unitPrice * $remainingMonths;

        $amountPaise = $amount * 100; // Razorpay uses paise

        // Create Razorpay order
        $razorpayKeyId = $this->razorpay->keyId();
        try {
            $order = $this->razorpay->createOrder([
                'amount'          => (int) $amountPaise,
                'currency'        => 'INR',
                'receipt'         => 'seats_' . $org->id . '_' . time(),
                'notes'           => [
                    'organization_id'  => $org->id,
                    'subscription_id'  => $sub->id,
                    'quantity'         => $quantity,
                    'remaining_months' => $remainingMonths,
                ],
            ]);
        } catch (Throwable) {
            return response()->json([
                'message' => 'Failed to create payment order. Please try again.',
            ], 502);
        }

        // Create a pending payment record so the transaction is traceable even
        // if the user closes Razorpay without completing payment.
        $monthSuffix = $remainingMonths > 1 ? " ({$remainingMonths} months)" : '';
        $seatLabel   = $quantity . ' Extra Team Seat' . ($quantity > 1 ? 's' : '') . $monthSuffix;
        SubscriptionPayment::create([
            'organization_id'  => $org->id,
            'subscription_id'  => $sub->id,
            'user_id'          => $user->id,
            'type'             => 'seat_purchase',
            'gateway'          => 'razorpay',
            'gateway_order_id' => $order['id'],
            'quantity'         => $quantity,
            'amount'           => $amount,
            'currency'         => 'INR',
            'status'           => 'pending',
            'valid_until'      => $sub->end_date?->toDateString(),
            'description'      => $seatLabel,
        ]);

        return response()->json([
            'data' => [
                'order_id' => $order['id'],
                'amount'   => $amountPaise,
                'currency' => 'INR',
                'key'      => $razorpayKeyId,
                'quantity' => $quantity,
            ],
        ]);
    }

    // ── POST /api/subscription/seats/verify ──────────────────────────────────

    public function verify(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        $data = $request->validate([
            'razorpay_order_id'   => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature'  => ['required', 'string'],
            'quantity'            => ['required', 'integer', 'min:1', 'max:' . self::MAX_SEATS_PER_PURCHASE],
        ]);

        // Verify HMAC-SHA256 signature
        if (! $this->razorpay->verifyOrderSignature(
            $data['razorpay_order_id'],
            $data['razorpay_payment_id'],
            $data['razorpay_signature']
        )) {
            return response()->json(['message' => 'Payment verification failed. Invalid signature.'], 422);
        }

        $pending = SubscriptionPayment::where('gateway_order_id', $data['razorpay_order_id'])
            ->where('organization_id', $org->id)
            ->where('status', 'pending')
            ->first();
        $payment = $this->razorpay->fetchPayment($data['razorpay_payment_id']);
        if (! $pending
            || ($payment['order_id'] ?? null) !== $data['razorpay_order_id']
            || ($payment['status'] ?? null) !== 'captured'
            || (int) ($payment['amount'] ?? 0) !== (int) round(((float) $pending->amount) * 100)
            || (int) $pending->quantity !== (int) $data['quantity']) {
            return response()->json(['message' => 'Razorpay did not confirm a captured seat payment matching this order.'], 422);
        }

        $sub = $org->activeSubscription();

        if (! $sub) {
            return response()->json(['message' => 'No active subscription found.'], 404);
        }

        // Increment extra seats
        $sub->increment('extra_members_purchased', $data['quantity']);

        // Mark the matching pending payment as completed
        $pending->update([
                'status'             => 'completed',
                'gateway_payment_id' => $data['razorpay_payment_id'],
        ]);

        return response()->json([
            'data'    => new SubscriptionResource($sub->fresh()),
            'message' => "{$data['quantity']} seat(s) added successfully.",
        ]);
    }

    // ── DELETE /api/subscription/seats/remove ────────────────────────────────

    public function remove(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        $sub = $org->activeSubscription();

        if (! $sub || (int) ($sub->extra_members_purchased ?? 0) <= 0) {
            return response()->json(['message' => 'No purchased seats to remove.'], 422);
        }

        // Safety: ensure removing a seat won't orphan existing team members
        $basePlanSeats = match ($sub->plan) {
            'enterprise' => 10,
            'business'   => 3,
            default      => 0,
        };
        $totalAfterRemoval = $basePlanSeats + $sub->extra_members_purchased - 1;

        $currentMemberCount = User::where('organization_id', $org->id)
            ->where('role', '!=', 'owner')
            ->whereNull('deleted_at')
            ->count();

        if ($currentMemberCount > $totalAfterRemoval) {
            return response()->json([
                'message' => "Cannot remove seat: you currently have {$currentMemberCount} team members. Remove a team member first.",
                'code'    => 'SEAT_IN_USE',
            ], 422);
        }

        $sub->decrement('extra_members_purchased', 1);

        return response()->json([
            'data'    => new SubscriptionResource($sub->fresh()),
            'message' => '1 seat removed successfully.',
        ]);
    }
}
