<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingDocument;
use App\Models\BillingPaymentAllocation;
use App\Models\BillingProfile;
use App\Models\DealPayment;
use App\Services\Billing\BillingAuditService;
use App\Services\Billing\BillingPermissionService;
use App\Services\Billing\PaymentAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingPaymentController extends Controller
{
    public function __construct(private readonly PaymentAllocationService $allocations, private readonly BillingPermissionService $permissions, private readonly BillingAuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->require($request, 'view');
        $query = DealPayment::where('organization_id', $request->user()->organization_id)->with(['deal' => fn ($q) => $q->withTrashed(), 'billingAllocations.document']);
        if ($request->user()->isEmployee()) {
            $query->whereHas('deal', fn ($q) => $q->withTrashed()->where('assigned_to', $request->user()->id));
        }
        if ($request->filled('deal_id')) {
            $query->where('deal_id', $request->integer('deal_id'));
        }
        if ($request->filled('client_id')) {
            $query->whereHas('deal', fn ($q) => $q->withTrashed()->where('client_id', $request->integer('client_id')));
        }

        return response()->json($query->latest('payment_date')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function allocate(Request $request, BillingDocument $billingDocument): JsonResponse
    {
        $this->assertDocument($request, $billingDocument);
        $this->require($request, 'allocate_payments');
        $data = $request->validate(['deal_payment_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0']]);
        $payment = DealPayment::where('organization_id', $request->user()->organization_id)->findOrFail($data['deal_payment_id']);
        $allocation = $this->allocations->allocate($billingDocument, $payment, (string) $data['amount'], $request->user());
        $this->syncPaymentStatus($billingDocument);
        $this->audit->record($billingDocument->organization_id, $request->user(), 'payment.allocated', $allocation, null, $allocation->toArray(), $request);

        return response()->json(['data' => $allocation->load('payment')], 201);
    }

    public function reverse(Request $request, BillingPaymentAllocation $billingPaymentAllocation): JsonResponse
    {
        abort_unless($billingPaymentAllocation->organization_id === $request->user()->organization_id, 404);
        $this->require($request, 'allocate_payments');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        abort_if($billingPaymentAllocation->reversed_at, 422, 'Allocation already reversed.');
        $billingPaymentAllocation->update(['reversed_at' => now(), 'reversed_by' => $request->user()->id, 'reversal_reason' => $data['reason']]);
        $this->syncPaymentStatus($billingPaymentAllocation->document);
        $this->audit->record($billingPaymentAllocation->organization_id, $request->user(), 'payment.allocation_reversed', $billingPaymentAllocation, null, ['reason' => $data['reason']], $request);

        return response()->json(['data' => $billingPaymentAllocation->fresh()]);
    }

    private function syncPaymentStatus(BillingDocument $document): void
    {
        $allocated = (float) $document->allocations()->whereNull('reversed_at')->sum('amount');
        $status = $allocated <= 0 ? 'unpaid' : ($allocated + .00001 >= (float) $document->grand_total ? 'paid' : 'partially_paid');
        $document->forceFill(['payment_status' => $status])->save();
    }

    private function require(Request $request, string $action): void
    {
        $p = BillingProfile::where('organization_id', $request->user()->organization_id)->first();
        abort_unless($this->permissions->allows($request->user(), $p, $action), 403);
    }

    private function assertDocument(Request $request, BillingDocument $d): void
    {
        abort_unless($d->organization_id === $request->user()->organization_id, 404);
        if ($request->user()->isEmployee()) {
            abort_unless(in_array($request->user()->id, [$d->assigned_to, $d->created_by], true), 403);
        }
    }
}
