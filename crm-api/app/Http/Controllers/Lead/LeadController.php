<?php

namespace App\Http\Controllers\Lead;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lead\LeadRequest;
use App\Http\Resources\LeadResource;
use App\Http\Resources\LeadTimelineResource;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\Department;
use App\Models\Lead;
use App\Models\LeadTimeline;
use App\Models\Notification;
use App\Models\Pipeline;
use App\Models\RecurringBusiness;
use App\Models\Stage;
use App\Models\User;
use App\Services\LeadAuthorizationService;
use App\Services\DealStatusService;
use App\Services\RecurringRevenueService;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    public function __construct(
        private readonly LeadAuthorizationService $leadAuth,
        private readonly DealStatusService $dealStatus,
        private readonly RecurringRevenueService $recurringRevenue,
    ) {}

    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    // ── GET /api/leads/department-counts ──────────────────────────────────────

    public function departmentCounts(Request $request): JsonResponse
    {
        if (! $request->user()->isOwner()) {
            return response()->json(['message' => 'You are not allowed to view department lead counts.'], 403);
        }

        $orgId = $this->orgId($request);

        // Live leads only (Lead uses SoftDeletes — Eloquent excludes deleted_at automatically).
        $total = Lead::where('organization_id', $orgId)->count();

        // Count live leads per department via assigned employee. Raw joins skip Eloquent
        // global scopes, so deleted leads/users/departments must be excluded explicitly.
        $countsByDepartment = Lead::query()
            ->join('users', function ($join) {
                $join->on('users.id', '=', 'leads.assigned_to')
                     ->whereNull('users.deleted_at');
            })
            ->join('department_user', 'department_user.user_id', '=', 'users.id')
            ->join('departments', function ($join) use ($orgId) {
                $join->on('departments.id', '=', 'department_user.department_id')
                     ->where('departments.organization_id', '=', $orgId)
                     ->whereNull('departments.deleted_at');
            })
            ->where('leads.organization_id', $orgId)
            ->groupBy('departments.id')
            ->select('departments.id', DB::raw('COUNT(DISTINCT leads.id) as leads_count'))
            ->pluck('leads_count', 'id');

        $departments = Department::query()
            ->where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($dept) => [
                'id'          => (int) $dept->id,
                'name'        => $dept->name,
                'leads_count' => (int) ($countsByDepartment[$dept->id] ?? 0),
            ])
            ->values();

        return response()->json([
            'data' => [
                'total'       => $total,
                'departments' => $departments,
            ],
        ]);
    }

    // ── GET /api/leads ────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Lead::where('organization_id', $this->orgId($request))
                     ->with(['stage', 'pipeline', 'assignedTo', 'createdBy', 'client', 'department'])
                     ->withCount('proposals');

        if ($request->user()->isEmployee()) {
            $query->where('assigned_to', $request->user()->id);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name',  'like', "%{$search}%")
                  ->orWhere('email',      'like', "%{$search}%")
                  ->orWhere('company',    'like', "%{$search}%")
                  ->orWhere('phone',      'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }

        if ($types = $request->input('types')) {
            $query->where('types', $types);
        }

        foreach (['client_type', 'business_type', 'market_type'] as $field) {
            if ($value = $request->input($field)) {
                $query->where($field, $value);
            }
        }

        if ($stageId = $request->input('stage_id')) {
            $query->where('stage_id', $stageId);
        }

        if ($pipelineId = $request->input('pipeline_id')) {
            $query->where('pipeline_id', $pipelineId);
        }

        if ($request->boolean('unassigned')) {
            $query->whereNull('assigned_to');
        } elseif ($assignedTo = $request->input('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->where('lead_date', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->where('lead_date', '<=', $dateTo);
        }

        if ($departmentId = $request->input('department_id')) {
            if (! $request->user()->isOwner()) {
                return response()->json(['message' => 'You are not allowed to filter leads by department.'], 403);
            }

            $departmentExists = Department::where('id', $departmentId)
                ->where('organization_id', $this->orgId($request))
                ->exists();

            if (! $departmentExists) {
                return response()->json(['message' => 'Invalid department.'], 422);
            }

            $query->whereHas('assignedTo.departments', fn ($q) => $q->where('departments.id', $departmentId));
        }

        $sortBy  = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $allowed = ['created_at', 'lead_date', 'first_name', 'last_name', 'email', 'company', 'score', 'status'];

        if (in_array($sortBy, $allowed)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $leads = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => LeadResource::collection($leads),
            'meta' => [
                'total'        => $leads->total(),
                'per_page'     => $leads->perPage(),
                'current_page' => $leads->currentPage(),
                'last_page'    => $leads->lastPage(),
            ],
        ]);
    }

    // ── POST /api/leads ───────────────────────────────────────────────────────

    public function store(LeadRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->leadAuth->canCreate($user)) {
            return response()->json(['message' => 'You are not allowed to create leads.'], 403);
        }

        // Plan lead limits
        $org = $user->organization;
        if ($org?->isEnterprisePlan()) {
            // Enterprise: unlimited — no check
        } elseif ($org?->isBusinessPlan()) {
            $count = Lead::where('organization_id', $this->orgId($request))->count();
            if ($count >= 5000) {
                return response()->json([
                    'message' => 'You have reached the 5,000-lead limit on the Business plan. Upgrade to Enterprise for unlimited leads.',
                    'code'    => 'LIMIT_REACHED',
                ], 403);
            }
        } else {
            $count = Lead::where('organization_id', $this->orgId($request))->count();
            if ($count >= 100) {
                return response()->json([
                    'message' => 'You have reached the 100-lead limit on the Free plan. Upgrade to Business or Enterprise.',
                    'code'    => 'LIMIT_REACHED',
                ], 403);
            }
        }

        $payload = $this->leadAuth->attributesForCreate($user, $request->validated());

        if (($payload['client_type'] ?? null) === 'EXISTING') {
            $client = $this->visibleClientQuery($request)->findOrFail($payload['client_id']);
            $payload = array_merge($payload, $this->clientSnapshot($client));
        }

        $payload['department_id'] ??= $this->departmentForAssignee($payload['assigned_to'] ?? null, $this->orgId($request));

        $lead = Lead::create(array_merge($payload, [
            'organization_id' => $this->orgId($request),
            'created_by'      => $user->id,
            'lead_date'       => now()->toDateString(),
        ]));

        // ── Auto-log: lead created ─────────────────────────────────────────────
        LeadTimeline::log(
            $lead,
            'created',
            "Lead created by {$user->name}",
            $user->id,
            ['source' => $lead->source, 'status' => $lead->status]
        );

        return response()->json([
            'data'    => new LeadResource($lead->load(['stage', 'pipeline', 'assignedTo', 'client', 'department'])),
            'message' => 'Lead created successfully.',
        ], 201);
    }

    // ── GET /api/leads/{lead} ─────────────────────────────────────────────────

    public function show(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeOrg($request, $lead);

        $lead->loadCount('proposals');
        $lead->load(['stage', 'pipeline', 'assignedTo', 'createdBy', 'client', 'department', 'activities', 'notes.createdBy']);

        return response()->json(['data' => new LeadResource($lead)]);
    }

    // ── PUT /api/leads/{lead} ─────────────────────────────────────────────────

    public function update(LeadRequest $request, Lead $lead): JsonResponse
    {
        $this->authorizeOrg($request, $lead);

        $user = $request->user();

        if (! $this->leadAuth->canUpdate($user, $lead)) {
            return response()->json(['message' => 'You are not allowed to edit this lead.'], 403);
        }

        $validated   = $this->leadAuth->attributesForUpdate($user, $request->validated());
        if (($validated['client_type'] ?? $lead->client_type) === 'EXISTING' && isset($validated['client_id'])) {
            $client = $this->visibleClientQuery($request)->findOrFail($validated['client_id']);
            $validated = array_merge($validated, $this->clientSnapshot($client));
        }
        if (array_key_exists('assigned_to', $validated) && ! array_key_exists('department_id', $validated)) {
            $validated['department_id'] = $this->departmentForAssignee($validated['assigned_to'], $this->orgId($request));
        }
        $scheduleAt  = $validated['schedule_at'] ?? null;
        $remark      = isset($validated['remark']) ? trim((string) $validated['remark']) : null;
        if ($remark === '') {
            $remark = null;
        }
        unset($validated['schedule_at'], $validated['remark']);

        // Guard: converted leads are status-locked — only non-status fields may be updated
        if ($lead->status === 'converted' && isset($validated['status']) && $validated['status'] !== 'converted') {
            unset($validated['status']);   // silently strip the status change; other fields still save
        }

        // Detect field-level changes before saving
        $changed = [];
        foreach ($validated as $field => $newVal) {
            $oldVal = $lead->getRawOriginal($field);
            if ((string) $oldVal !== (string) ($newVal ?? '')) {
                $changed[$field] = ['from' => $oldVal, 'to' => $newVal];
            }
        }

        $lead->update($validated);

        if (!empty($changed)) {
            $statusChanged   = isset($changed['status']);
            $assignedChanged = isset($changed['assigned_to']);

            if ($statusChanged) {
                LeadTimeline::log(
                    $lead,
                    'status_changed',
                    "Status changed from «{$changed['status']['from']}» to «{$changed['status']['to']}»",
                    $user->id,
                    $changed
                );

                // Ringing / Important — log remark as activity description
                if (in_array($changed['status']['to'], ['ringing', 'important'], true)) {
                    $isRinging    = $changed['status']['to'] === 'ringing';
                    $activityType = $isRinging ? 'call' : 'note';
                    $title        = $isRinging
                        ? "Ringing — {$lead->full_name}"
                        : "Important — {$lead->full_name}";

                    $activity = Activity::create([
                        'organization_id' => $lead->organization_id,
                        'created_by'      => $user->id,
                        'assigned_to'     => $lead->assigned_to ?? $user->id,
                        'subject_type'    => Lead::class,
                        'subject_id'      => $lead->id,
                        'type'            => $activityType,
                        'title'           => $title,
                        'description'     => $remark,
                        'due_at'          => null,
                        'priority'        => $isRinging ? 'medium' : 'high',
                        'is_done'         => false,
                    ]);

                    LeadTimeline::log(
                        $lead,
                        'status_remark',
                        ($isRinging ? 'Ringing' : 'Important') . ' status set'
                            . ($remark ? ": {$remark}" : ''),
                        $user->id,
                        [
                            'activity_id' => $activity->id,
                            'type'        => $activityType,
                            'status'      => $changed['status']['to'],
                            'remark'      => $remark,
                        ]
                    );
                }

                // Cascade "lost" status to all linked deals + move each to its pipeline's lost stage
                if ($changed['status']['to'] === 'lost') {
                    $linkedDeals = \App\Models\Deal::where('lead_id', $lead->id)
                        ->where('status', '!=', 'lost')
                        ->get();

                    foreach ($linkedDeals as $linkedDeal) {
                        $lostStage = \App\Models\Stage::where('pipeline_id', $linkedDeal->pipeline_id)
                            ->where('is_lost', true)
                            ->first();

                        $linkedDeal->update([
                            'status'      => 'lost',
                            'stage_id'    => $lostStage ? $lostStage->id : $linkedDeal->stage_id,
                            'closed_at'   => now(),
                            'lost_reason' => $lead->lost_reason ?? null,
                        ]);
                    }
                }
            } elseif ($assignedChanged) {
                $newAssigneeId = $changed['assigned_to']['to'] ?: null;
                $assigneeName  = 'Unassigned';

                if ($newAssigneeId) {
                    $assignee     = \App\Models\User::find($newAssigneeId);
                    $assigneeName = $assignee ? $assignee->name : 'Unknown';
                }

                // Cascade assignment to all linked deals
                \App\Models\Deal::where('lead_id', $lead->id)
                    ->update(['assigned_to' => $newAssigneeId]);

                // Cascade assignment to all linked activities
                Activity::where(function ($q) {
                    $q->where('subject_type', 'lead')
                      ->orWhere('subject_type', Lead::class);
                })
                    ->where('subject_id', $lead->id)
                    ->update(['assigned_to' => $newAssigneeId]);

                LeadTimeline::log(
                    $lead,
                    'assigned',
                    "Assigned to {$assigneeName} by {$user->name} (deals & activities updated)",
                    $user->id,
                    array_merge($changed, ['assignee_name' => $assigneeName])
                );

                // Notify new assignee if different from current user
                if ($newAssigneeId && $newAssigneeId !== $user->id) {
                    Notification::notify(
                        $lead->organization_id,
                        $newAssigneeId,
                        'lead_assigned',
                        'Lead Assigned to You',
                        "{$user->name} assigned \"{$lead->full_name}\" to you",
                        ['lead_id' => $lead->id],
                        "/leads/{$lead->id}"
                    );
                }
            } else {
                LeadTimeline::log(
                    $lead,
                    'updated',
                    "Lead details updated by {$user->name} (" . implode(', ', array_keys($changed)) . ")",
                    $user->id,
                    $changed
                );
            }
        }

        // Create or reschedule follow-up / meeting when a due time is provided.
        // Re-selecting the same status updates the latest activity timing.
        if ($scheduleAt && in_array($lead->status, ['followup', 'meeting'], true)) {
            $justSetScheduleStatus = isset($changed['status'])
                && in_array($changed['status']['to'], ['followup', 'meeting'], true);

            $this->upsertScheduledActivity(
                $lead,
                $user,
                $lead->status,
                $scheduleAt,
                $remark,
                $justSetScheduleStatus
            );
        }

        return response()->json([
            'data'    => new LeadResource($lead->fresh(['stage', 'pipeline', 'assignedTo'])),
            'message' => 'Lead updated successfully.',
        ]);
    }

    // ── DELETE /api/leads/{lead} ──────────────────────────────────────────────

    public function destroy(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeOrg($request, $lead);

        if (! $this->leadAuth->canDelete($request->user())) {
            return response()->json(['message' => 'Only managers can delete leads.'], 403);
        }

        $user = $request->user();
        $name = $lead->full_name;

        // Log before soft-delete (FK cascade keeps timeline rows)
        LeadTimeline::log(
            $lead,
            'deleted',
            "Lead «{$name}» deleted by {$user->name}",
            $user->id
        );

        $lead->delete();

        return response()->json(['message' => 'Lead deleted successfully.']);
    }

    // ── POST /api/leads/bulk-import ───────────────────────────────────────────

    public function bulkImport(Request $request): JsonResponse
    {
        $request->validate([
            'leads'              => ['required', 'array', 'max:500'],
            'leads.*.first_name' => ['required', 'string', 'max:191'],
            'leads.*.email'      => ['nullable', 'email', 'max:191'],
            'leads.*.phone'      => ['nullable', 'string', 'max:30'],
        ]);

        $orgId  = $this->orgId($request);
        $userId = $request->user()->id;
        $now    = now();
        $rows   = [];
        foreach ($request->input('leads') as $row) {
            $normalizedPhone = PhoneNormalizer::normalize($row['phone'] ?? null);

            $rows[] = array_merge($row, [
                'phone_normalized' => $normalizedPhone,
                'organization_id'  => $orgId,
                'created_by'       => $userId,
                'lead_date'        => $now->toDateString(),
                'client_type'      => $row['client_type'] ?? 'NEW',
                'business_type'    => $row['business_type'] ?? 'ONE_TIME',
                'market_type'      => $row['market_type'] ?? 'DOMESTIC',
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }

        Lead::insert($rows);

        return response()->json([
            'message' => count($rows) . ' leads imported successfully.',
            'count'   => count($rows),
        ], 201);
    }

    // ── GET /api/leads/{lead}/timeline ────────────────────────────────────────

    public function timeline(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeOrg($request, $lead);

        $entries = LeadTimeline::where('lead_id', $lead->id)
                               ->with('user')
                               ->latest()
                               ->get();

        return response()->json([
            'data' => LeadTimelineResource::collection($entries),
        ]);
    }

    // ── POST /api/leads/{lead}/convert ────────────────────────────────────────

    public function convertToDeal(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeOrg($request, $lead);

        if ($lead->status === 'converted') {
            return response()->json(['message' => 'This lead has already been converted to a deal.'], 422);
        }

        if (! $this->leadAuth->canUpdate($request->user(), $lead)) {
            return response()->json(['message' => 'You are not allowed to convert this lead.'], 403);
        }

        $request->validate([
            'pipeline_id'              => ['required', 'integer', 'exists:pipelines,id'],
            'stage_id'                 => ['required', 'integer', 'exists:stages,id'],
            'title'                    => ['nullable', 'string', 'max:191'],
            'value'                    => ['nullable', 'numeric', 'min:0'],
            'currency'                 => ['nullable', 'in:INR,USD'],
            'payment.amount'           => ['nullable', 'numeric', 'min:0.01'],
            'payment.payment_date'     => ['nullable', 'required_with:payment.amount', 'date'],
            'payment.payment_mode'     => ['nullable', 'required_with:payment.amount', 'in:cash,cheque,bank_transfer,upi,card,aggregator,other'],
            'payment.txn_or_utr_number'=> ['nullable', 'string', 'max:100'],
            'payment.notes'            => ['nullable', 'string', 'max:500'],
        ]);

        $pipeline = Pipeline::where('organization_id', $this->orgId($request))->find($request->integer('pipeline_id'));
        $stage = Stage::where('pipeline_id', $request->integer('pipeline_id'))->find($request->integer('stage_id'));
        if (! $pipeline || ! $stage) {
            return response()->json(['message' => 'Invalid pipeline or stage.'], 422);
        }

        $user       = $request->user();
        $oldStatus  = $lead->status;
        $dealMarkedWon = false;

        $deal = DB::transaction(function () use ($request, $lead, $user, &$dealMarkedWon) {
            $client = $lead->client;
            if (! $client) {
                $client = Client::create([
                    'organization_id' => $lead->organization_id,
                    'created_by' => $user->id,
                    'assigned_to' => $lead->assigned_to,
                    ...$this->clientSnapshot($lead),
                ]);
                $lead->update(['client_id' => $client->id, 'client_type' => $lead->client_type ?: 'NEW']);
            }

            $businessType = $lead->business_type ?: 'ONE_TIME';
            $contractValue = $lead->contract_value;
            if ($businessType === 'RECURRING' && $contractValue === null && $lead->billing_cycles) {
                $contractValue = round((float) $lead->recurring_amount * (int) $lead->billing_cycles, 2);
            }
            $nextBilling = $lead->next_billing_date;
            if ($businessType === 'RECURRING' && ! $nextBilling) {
                $nextBilling = $this->recurringRevenue->nextBillingDate($lead->recurring_start_date, $lead->recurring_frequency)->toDateString();
            }
            $dealValue = $request->input('value');
            if ($dealValue === null) {
                $dealValue = $businessType === 'RECURRING'
                    ? ($contractValue ?? $lead->expected_value ?? $lead->recurring_amount)
                    : $lead->expected_value;
            }

            $deal = Deal::create([
                'organization_id' => $this->orgId($request),
                'client_id'       => $client->id,
                'lead_id'         => $lead->id,
                'created_by'      => $user->id,
                'assigned_to'     => $lead->assigned_to,
                'department_id'   => $lead->department_id,
                'pipeline_id'     => $request->input('pipeline_id'),
                'stage_id'        => $request->input('stage_id'),
                'title'           => $request->input('title', "Deal — {$lead->full_name}"),
                'value'           => $dealValue,
                'currency'        => $request->input('currency', 'INR') === 'USD' ? 'USD' : 'INR',
                'status'          => 'open',
                'client_type'     => $lead->client_type ?: 'NEW',
                'business_type'   => $businessType,
                'market_type'     => $lead->market_type,
                'service_type'    => $lead->types,
                'recurring_frequency' => $lead->recurring_frequency,
                'recurring_amount' => $lead->recurring_amount,
                'recurring_start_date' => $lead->recurring_start_date,
                'recurring_end_date' => $lead->recurring_end_date,
                'next_billing_date' => $nextBilling,
                'billing_cycles' => $lead->billing_cycles,
                'contract_value' => $contractValue,
            ]);

            if ($businessType === 'RECURRING') {
                RecurringBusiness::create([
                    'organization_id' => $lead->organization_id,
                    'client_id' => $client->id,
                    'lead_id' => $lead->id,
                    'deal_id' => $deal->id,
                    'assigned_to' => $lead->assigned_to,
                    'department_id' => $lead->department_id,
                    'created_by' => $user->id,
                    'business_name' => $deal->title,
                    'service_type' => $lead->types,
                    'amount' => $lead->recurring_amount,
                    'currency' => $deal->currency,
                    'frequency' => $lead->recurring_frequency,
                    'start_date' => $lead->recurring_start_date,
                    'end_date' => $lead->recurring_end_date,
                    'next_billing_date' => $nextBilling,
                    'billing_cycles' => $lead->billing_cycles,
                    'contract_value' => $contractValue,
                    'status' => 'ACTIVE',
                    'notes' => $lead->notes,
                ]);
            }

            if ($request->filled('payment.amount')) {
                DealPayment::create([
                    'deal_id'           => $deal->id,
                    'organization_id'   => $deal->organization_id,
                    'created_by'        => $user->id,
                    'amount'            => $request->input('payment.amount'),
                    'payment_date'      => $request->input('payment.payment_date', now()->toDateString()),
                    'payment_mode'      => $request->input('payment.payment_mode', 'other'),
                    'txn_or_utr_number' => $request->input('payment.txn_or_utr_number'),
                    'notes'             => $request->input('payment.notes'),
                ]);
            }

            $deal->refresh();
            $dealMarkedWon = $this->dealStatus->markWonIfFullyPaid($deal);

            $lead->update(['status' => 'converted']);

            return $deal->fresh(['stage', 'pipeline', 'client', 'department']);
        });

        LeadTimeline::log(
            $lead,
            'deal_created',
            "Converted to deal «{$deal->title}» by {$user->name}",
            $user->id,
            ['deal_id' => $deal->id, 'deal_title' => $deal->title]
        );

        LeadTimeline::log(
            $lead,
            'status_changed',
            "Status changed from «{$oldStatus}» to «converted» (deal created)",
            $user->id,
            ['from' => $oldStatus, 'to' => 'converted']
        );

        if ($dealMarkedWon) {
            LeadTimeline::log(
                $lead,
                'deal_won',
                "Deal «{$deal->title}» marked as Won — full payment received",
                $user->id,
                ['deal_id' => $deal->id, 'deal_status' => 'won']
            );
        }

        $message = $dealMarkedWon
            ? 'Lead converted to deal and marked as Won — full amount received.'
            : 'Lead converted to deal successfully.';

        return response()->json([
            'data'            => $deal,
            'deal_status'     => $deal->status,
            'deal_marked_won' => $dealMarkedWon,
            'message'         => $message,
        ], 201);
    }

    /** @return array<string, mixed> */
    private function clientSnapshot(Client|Lead $source): array
    {
        return [
            'first_name' => $source->first_name,
            'last_name' => $source->last_name,
            'company' => $source->company,
            'email' => $source->email,
            'phone' => $source->phone,
            'job_title' => $source->job_title,
            'website' => $source->website,
            'city' => $source->city,
            'state' => $source->state,
            'country' => $source->country,
        ];
    }

    private function departmentForAssignee(?int $userId, int $organizationId): ?int
    {
        if (! $userId) return null;
        return User::where('organization_id', $organizationId)->find($userId)?->departments()->value('departments.id');
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * @param  bool  $forceCreate  True when status just changed to followup/meeting.
     */
    private function upsertScheduledActivity(
        Lead $lead,
        $user,
        string $status,
        string $scheduleAt,
        ?string $remark,
        bool $forceCreate,
    ): void {
        $isMeeting    = $status === 'meeting';
        $activityType = $isMeeting ? 'meeting' : 'task';
        $label        = $isMeeting ? 'Meeting' : 'Follow-up';
        $title        = $isMeeting
            ? "Meeting with {$lead->full_name}"
            : "Follow up with {$lead->full_name}";
        $dueAt = \Carbon\Carbon::parse($scheduleAt);

        $subjectTypes = ['lead', Lead::class];

        // Only update the latest open schedule. Never reopen/mutate a completed
        // one by falling back to an older pending activity — create a new one instead.
        $activity     = null;
        $wasCompleted = false;
        if (! $forceCreate) {
            $latest = Activity::where('subject_id', $lead->id)
                ->where(function ($q) use ($subjectTypes) {
                    foreach ($subjectTypes as $type) {
                        $q->orWhere('subject_type', $type);
                    }
                })
                ->where('type', $activityType)
                ->whereNotNull('due_at')
                ->orderByDesc('created_at')
                ->first();

            if ($latest && ! $latest->is_done) {
                $activity = $latest;
            } elseif ($latest) {
                $wasCompleted = true;
            }
        }

        if ($activity) {
            $updates = [
                'due_at'        => $dueAt,
                'is_done'       => false,
                'completed_at'  => null,
                'assigned_to'   => $lead->assigned_to ?? $user->id,
                'title'         => $title,
            ];
            if ($remark !== null) {
                $updates['description'] = $remark;
            }

            $activity->update($updates);
            $activity->refresh();

            $this->retireStaleScheduledActivities($lead, $activityType, $activity->id);

            LeadTimeline::log(
                $lead,
                'activity_scheduled',
                "{$label} rescheduled for " . $activity->due_at->format('d M Y, h:i A'),
                $user->id,
                [
                    'activity_id' => $activity->id,
                    'due_at'      => $activity->due_at->toIso8601String(),
                    'type'        => $activityType,
                    'remark'      => $remark,
                    'rescheduled' => true,
                ]
            );

            $lead->touch();

            return;
        }

        $activity = Activity::create([
            'organization_id' => $lead->organization_id,
            'created_by'      => $user->id,
            'assigned_to'     => $lead->assigned_to ?? $user->id,
            'subject_type'    => Lead::class,
            'subject_id'      => $lead->id,
            'type'            => $activityType,
            'title'           => $title,
            'description'     => $remark,
            'due_at'          => $dueAt,
            'priority'        => 'medium',
            'is_done'         => false,
        ]);

        $this->retireStaleScheduledActivities($lead, $activityType, $activity->id);

        $verb = $wasCompleted ? 'rescheduled' : 'scheduled';

        LeadTimeline::log(
            $lead,
            $wasCompleted ? 'activity_scheduled' : 'followup_created',
            "{$label} {$verb} for " . $activity->due_at->format('d M Y, h:i A'),
            $user->id,
            [
                'activity_id' => $activity->id,
                'due_at'      => $activity->due_at->toIso8601String(),
                'type'        => $activityType,
                'remark'      => $remark,
                'rescheduled' => $wasCompleted,
            ]
        );
    }

    /**
     * Soft-delete other open follow-up/meeting activities so list pages show one current schedule.
     */
    private function retireStaleScheduledActivities(Lead $lead, string $activityType, int $keepId): void
    {
        $subjectTypes = ['lead', Lead::class];

        Activity::where('subject_id', $lead->id)
            ->where(function ($q) use ($subjectTypes) {
                foreach ($subjectTypes as $type) {
                    $q->orWhere('subject_type', $type);
                }
            })
            ->where('type', $activityType)
            ->where('is_done', false)
            ->where('id', '!=', $keepId)
            ->delete();
    }

    private function authorizeOrg(Request $request, Lead $lead): void
    {
        if ($lead->organization_id !== $this->orgId($request)) {
            abort(404);
        }
        if ($request->user()->isEmployee() && $lead->assigned_to !== $request->user()->id) {
            abort(404);
        }
    }

    private function visibleClientQuery(Request $request): Builder
    {
        $user = $request->user();

        return Client::query()
            ->where('organization_id', $this->orgId($request))
            ->when($user->isEmployee(), fn ($q) => $q->where(fn ($visible) => $visible
                ->where('assigned_to', $user->id)
                ->orWhereHas('leads', fn ($lead) => $lead->where('assigned_to', $user->id))
                ->orWhereHas('deals', fn ($deal) => $deal->where('assigned_to', $user->id))));
    }
}
