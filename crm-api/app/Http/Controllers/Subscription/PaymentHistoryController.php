<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionPaymentResource;
use App\Models\SubscriptionPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentHistoryController extends Controller
{
    // ── GET /api/subscription/payments ───────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $payments = SubscriptionPayment::where('organization_id', $orgId)
            ->with('user')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => SubscriptionPaymentResource::collection($payments),
            'meta' => [
                'total'        => $payments->total(),
                'per_page'     => $payments->perPage(),
                'current_page' => $payments->currentPage(),
                'last_page'    => $payments->lastPage(),
            ],
        ]);
    }

    // ── GET /api/subscription/payments/{payment} ──────────────────────────────

    public function show(Request $request, SubscriptionPayment $payment): JsonResponse
    {
        // Ensure the payment belongs to the authenticated user's organization
        if ($payment->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        $payment->load('user', 'organization');

        return response()->json(['data' => new SubscriptionPaymentResource($payment)]);
    }
}
