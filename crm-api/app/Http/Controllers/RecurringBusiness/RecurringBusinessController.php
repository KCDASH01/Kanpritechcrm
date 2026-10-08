<?php

namespace App\Http\Controllers\RecurringBusiness;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecurringBusinessResource;
use App\Models\RecurringBusiness;
use App\Services\RecurringRevenueService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecurringBusinessController extends Controller
{
    public function __construct(private readonly RecurringRevenueService $revenue) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:191'], 'status' => ['nullable', 'in:ACTIVE,PAUSED,EXPIRED,CANCELLED,COMPLETED'],
            'frequency' => ['nullable', 'in:MONTHLY,QUARTERLY,HALF_YEARLY,YEARLY'], 'assigned_to' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $this->visibleQuery($request)->with(['client', 'deal.lead', 'deal.payments', 'assignedTo', 'department']);
        if ($search = trim((string) $request->input('search'))) {
            $query->where(fn ($q) => $q->where('business_name', 'like', "%{$search}%")->orWhere('service_type', 'like', "%{$search}%")
                ->orWhereHas('client', fn ($c) => $c->where('company', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")));
        }
        foreach (['status', 'frequency', 'assigned_to', 'department_id'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->input($field));
        }
        $allForSummary = (clone $query)->get();
        $rows = $query->orderBy('next_billing_date')->paginate($request->input('per_page', 20));
        return response()->json([
            'data' => ['summary' => $this->revenue->summary($allForSummary, now()), 'rows' => RecurringBusinessResource::collection($rows->items())->resolve()],
            'meta' => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()],
        ]);
    }

    public function show(Request $request, RecurringBusiness $recurringBusiness): JsonResponse
    {
        $this->authorizeVisible($request, $recurringBusiness);
        return response()->json(['data' => new RecurringBusinessResource($recurringBusiness->load(['client', 'deal.lead', 'assignedTo', 'department']))]);
    }

    public function update(Request $request, RecurringBusiness $recurringBusiness): JsonResponse
    {
        $this->authorizeVisible($request, $recurringBusiness);
        $user = $request->user();
        if (! $user->isAdmin() && $recurringBusiness->assigned_to !== $user->id) return response()->json(['message' => 'Forbidden.'], 403);
        $data = $request->validate([
            'status' => ['sometimes', 'in:ACTIVE,PAUSED,EXPIRED,CANCELLED,COMPLETED'],
            'next_billing_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:'.$recurringBusiness->start_date->toDateString()],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:'.$recurringBusiness->start_date->toDateString()],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);
        if (($data['status'] ?? null) === 'PAUSED') $data['paused_at'] = now();
        if (($data['status'] ?? null) === 'ACTIVE') $data['paused_at'] = null;
        if (($data['status'] ?? null) === 'CANCELLED') $data['cancelled_at'] = now();
        $recurringBusiness->update($data);
        return response()->json(['data' => new RecurringBusinessResource($recurringBusiness->fresh(['client', 'deal.lead', 'assignedTo', 'department'])), 'message' => 'Recurring business updated successfully.']);
    }

    private function visibleQuery(Request $request): Builder
    {
        $user = $request->user();
        return RecurringBusiness::query()->where('organization_id', $user->organization_id)
            ->when($user->isEmployee(), fn ($q) => $q->where('assigned_to', $user->id));
    }

    private function authorizeVisible(Request $request, RecurringBusiness $business): void
    {
        if (! $this->visibleQuery($request)->whereKey($business->id)->exists()) abort(404);
    }
}
