<?php

namespace App\Http\Controllers\Deal;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Services\DealStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DealPaymentController extends Controller
{
    public function __construct(
        private readonly DealStatusService $dealStatus,
    ) {}
    // ── GET /api/deals/{deal}/payments ────────────────────────────────────────
    // List all payments for a deal. Accessible by org members.

    public function index(Request $request, Deal $deal): JsonResponse
    {
        if ($deal->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        $payments = $deal->payments()
            ->with('createdBy:id,name')
            ->orderBy('payment_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $total = (float) $payments->sum('amount');

        return response()->json([
            'data'  => $payments->map(fn (DealPayment $p) => [
                'id'                => $p->id,
                'amount'            => (float) $p->amount,
                'payment_date'      => $p->payment_date->toDateString(),
                'payment_mode'      => $p->payment_mode,
                'txn_or_utr_number' => $p->txn_or_utr_number,
                'notes'             => $p->notes,
                'created_by'        => $p->createdBy
                    ? ['id' => $p->createdBy->id, 'name' => $p->createdBy->name]
                    : null,
                'created_at'        => $p->created_at?->toIso8601String(),
            ]),
            'total' => $total,
        ]);
    }

    // ── POST /api/deals/{deal}/payments ───────────────────────────────────────
    // Add a new payment transaction. Open deals only.
    // Accessible by owner/admin OR the deal's assigned user.

    public function store(Request $request, Deal $deal): JsonResponse
    {
        if ($deal->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        $user      = $request->user();
        $canManage = in_array($user->role, ['owner', 'admin']);

        if (!$canManage && $deal->assigned_to !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($deal->status !== 'open') {
            return response()->json(['message' => 'Payments can only be added to open deals.'], 422);
        }

        $data = $request->validate([
            'amount'            => ['required', 'numeric', 'min:0.01'],
            'payment_date'      => ['required', 'date'],
            'payment_mode'      => ['required', 'in:cash,cheque,bank_transfer,upi,card,other'],
            'txn_or_utr_number' => ['nullable', 'string', 'max:100'],
            'notes'             => ['nullable', 'string', 'max:500'],
        ]);

        $dealMarkedWon = false;

        $payment = DB::transaction(function () use ($data, $deal, $user, &$dealMarkedWon) {
            $payment = DealPayment::create(array_merge($data, [
                'deal_id'         => $deal->id,
                'organization_id' => $deal->organization_id,
                'created_by'      => $user->id,
            ]));

            $deal->refresh();
            $dealMarkedWon = $this->dealStatus->markWonIfFullyPaid($deal);

            return $payment;
        });

        $payment->load('createdBy:id,name');
        $deal->refresh();

        $message = $dealMarkedWon
            ? 'Payment recorded. Deal marked as Won — full amount received.'
            : 'Payment recorded successfully.';

        return response()->json([
            'data' => [
                'id'                => $payment->id,
                'amount'            => (float) $payment->amount,
                'payment_date'      => $payment->payment_date->toDateString(),
                'payment_mode'      => $payment->payment_mode,
                'txn_or_utr_number' => $payment->txn_or_utr_number,
                'notes'             => $payment->notes,
                'created_by'        => $payment->createdBy
                    ? ['id' => $payment->createdBy->id, 'name' => $payment->createdBy->name]
                    : null,
                'created_at'        => $payment->created_at?->toIso8601String(),
            ],
            'deal_status'     => $deal->status,
            'deal_marked_won' => $dealMarkedWon,
            'message'         => $message,
        ], 201);
    }
}
