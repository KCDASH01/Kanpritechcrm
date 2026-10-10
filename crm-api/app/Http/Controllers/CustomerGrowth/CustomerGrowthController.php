<?php

namespace App\Http\Controllers\CustomerGrowth;

use App\Http\Controllers\Controller;
use App\Models\CustomerGrowthSetting;
use App\Models\Deal;
use App\Models\GrowthRecommendation;
use App\Models\RetentionTask;
use App\Services\CustomerGrowthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerGrowthController extends Controller
{
    public function __construct(private readonly CustomerGrowthService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string'], 'priority' => ['nullable', 'in:low,medium,high'],
            'recommendation_type' => ['nullable', 'in:cross_sell,upsell'], 'client_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'], 'service' => ['nullable', 'string', 'max:191'],
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        if (! $request->user()->isAdmin()) {
            unset($filters['assigned_to']);
        }

        return response()->json(['data' => $this->service->overview($request->user(), $filters)]);
    }

    public function refresh(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => ['created' => $this->service->refreshRecommendations($request->user()->organization_id)]]);
    }

    public function updateRecommendation(Request $request, GrowthRecommendation $recommendation): JsonResponse
    {
        $this->authorizeRecommendation($request, $recommendation);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['new', 'under_review', 'contact_planned', 'contacted', 'interested', 'converted', 'not_interested', 'snoozed'])],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('organization_id', $request->user()->organization_id)->where('is_active', true))],
            'suggested_follow_up_date' => ['sometimes', 'nullable', 'date'],
            'snoozed_until' => ['sometimes', 'nullable', 'date'],
            'dismissal_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'potential_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['required_with:potential_value', 'nullable', 'in:INR,USD'],
        ]);
        if (! $request->user()->isAdmin()) {
            unset($data['assigned_to']);
        }
        if (array_key_exists('potential_value', $data)) {
            $data['estimate_source'] = 'Manual estimate';
        }
        if (($data['status'] ?? null) === 'not_interested' && empty($data['dismissal_reason'])) {
            return response()->json(['message' => 'A reason is required when dismissing a recommendation.'], 422);
        }
        $recommendation->update($data);

        return response()->json(['data' => $recommendation->fresh(['client', 'assignedTo']), 'message' => 'Growth opportunity updated.']);
    }

    public function linkDeal(Request $request, GrowthRecommendation $recommendation): JsonResponse
    {
        $this->authorizeRecommendation($request, $recommendation);
        $data = $request->validate(['deal_id' => ['required', 'integer']]);
        $deal = Deal::query()->where('organization_id', $request->user()->organization_id)
            ->whereKey($data['deal_id'])->where('client_id', $recommendation->client_id)
            ->when(! $request->user()->isAdmin(), fn ($query) => $query->where('assigned_to', $request->user()->id))->firstOrFail();

        DB::transaction(function () use ($recommendation, $deal) {
            $locked = GrowthRecommendation::whereKey($recommendation->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->converted_deal_id && $locked->converted_deal_id !== $deal->id, 409, 'This recommendation is already linked to a deal.');
            $locked->update(['converted_deal_id' => $deal->id, 'status' => 'converted']);
        });

        return response()->json(['data' => $recommendation->fresh(['convertedDeal']), 'message' => 'Existing deal linked successfully.']);
    }

    public function candidateDeals(Request $request, GrowthRecommendation $recommendation): JsonResponse
    {
        $this->authorizeRecommendation($request, $recommendation);
        $deals = Deal::query()->where('organization_id', $request->user()->organization_id)
            ->where('client_id', $recommendation->client_id)
            ->when(! $request->user()->isAdmin(), fn ($query) => $query->where('assigned_to', $request->user()->id))
            ->latest()->get(['id', 'title', 'value', 'currency', 'status', 'closed_at']);

        return response()->json(['data' => $deals]);
    }

    public function storeRetentionTask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where(fn ($query) => $query->where('organization_id', $request->user()->organization_id)->whereNull('deleted_at'))],
            'recurring_business_id' => ['nullable', 'integer'], 'task_type' => ['required', Rule::in(['renewal', 'retention_follow_up', 'cancellation_review'])],
            'due_date' => ['nullable', 'date'], 'reason' => ['required', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:4000'],
            'assigned_to' => ['nullable', 'integer'],
        ]);
        if (! $request->user()->isAdmin()) {
            $data['assigned_to'] = $request->user()->id;
        }
        $fingerprint = hash('sha256', implode('|', [$data['client_id'], $data['recurring_business_id'] ?? 0, $data['task_type'], $data['due_date'] ?? 'none', $data['reason']]));
        $task = RetentionTask::firstOrCreate(
            ['organization_id' => $request->user()->organization_id, 'fingerprint' => $fingerprint],
            array_merge($data, ['assigned_to' => $data['assigned_to'] ?? $request->user()->id, 'status' => 'open']),
        );

        return response()->json(['data' => $task, 'message' => $task->wasRecentlyCreated ? 'Retention task created.' : 'This retention task already exists.'], $task->wasRecentlyCreated ? 201 : 200);
    }

    public function completeRetentionTask(Request $request, RetentionTask $task): JsonResponse
    {
        abort_unless($task->organization_id === $request->user()->organization_id, 404);
        abort_unless($request->user()->isAdmin() || $task->assigned_to === $request->user()->id, 403);
        $task->update(['status' => 'completed', 'completed_at' => now()]);

        return response()->json(['data' => $task->fresh(), 'message' => 'Retention task completed.']);
    }

    public function settings(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => $this->service->settings($request->user()->organization_id)]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'cross_sell_enabled' => ['sometimes', 'boolean'], 'upsell_enabled' => ['sometimes', 'boolean'],
            'renewal_reminders_enabled' => ['sometimes', 'boolean'], 'health_alerts_enabled' => ['sometimes', 'boolean'],
            'reminder_intervals' => ['sometimes', 'array'], 'reminder_intervals.*' => ['integer', 'between:-365,365'],
            'service_mappings' => ['sometimes', 'array'], 'service_mappings.*.source' => ['required', 'string', 'max:191'],
            'service_mappings.*.target' => ['required', 'string', 'max:191'], 'service_mappings.*.type' => ['required', 'in:cross_sell,upsell'],
            'service_mappings.*.reason' => ['nullable', 'string', 'max:1000'], 'service_mappings.*.priority' => ['nullable', 'in:low,medium,high'],
            'health_thresholds' => ['sometimes', 'array'], 'notification_channels' => ['sometimes', 'array'],
            'default_assignment' => ['sometimes', 'in:client_owner,contract_owner,current_user'],
        ]);
        $settings = CustomerGrowthSetting::updateOrCreate(['organization_id' => $request->user()->organization_id], $data);

        return response()->json(['data' => $settings, 'message' => 'Customer Growth settings saved.']);
    }

    private function authorizeRecommendation(Request $request, GrowthRecommendation $recommendation): void
    {
        abort_unless($recommendation->organization_id === $request->user()->organization_id, 404);
        abort_unless($request->user()->isAdmin() || $recommendation->assigned_to === $request->user()->id, 403);
    }
}
