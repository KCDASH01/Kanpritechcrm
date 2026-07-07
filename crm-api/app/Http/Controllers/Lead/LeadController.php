<?php

namespace App\Http\Controllers\Lead;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lead\LeadRequest;
use App\Http\Resources\LeadResource;
use App\Http\Resources\LeadTimelineResource;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadTimeline;
use App\Models\Notification;
use App\Services\LeadAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function __construct(
        private readonly LeadAuthorizationService $leadAuth,
    ) {}

    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    // ── GET /api/leads ────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Lead::where('organization_id', $this->orgId($request))
                     ->with(['stage', 'pipeline', 'assignedTo', 'createdBy'])
                     ->withCount('proposals');

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
            'data'    => new LeadResource($lead->load(['stage', 'pipeline', 'assignedTo'])),
            'message' => 'Lead created successfully.',
        ], 201);
    }

    // ── GET /api/leads/{lead} ─────────────────────────────────────────────────

    public function show(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeOrg($request, $lead);

        $lead->loadCount('proposals');
        $lead->load(['stage', 'pipeline', 'assignedTo', 'createdBy', 'activities', 'notes.createdBy']);

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
        $scheduleAt  = $validated['schedule_at'] ?? null;
        unset($validated['schedule_at']);

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

                if (in_array($changed['status']['to'], ['followup', 'meeting'], true)) {
                    $isMeeting    = $changed['status']['to'] === 'meeting';
                    $activityType = $isMeeting ? 'meeting' : 'task';
                    $title        = $isMeeting
                        ? "Meeting with {$lead->full_name}"
                        : "Follow up with {$lead->full_name}";

                    $activity = Activity::create([
                        'organization_id' => $lead->organization_id,
                        'created_by'      => $user->id,
                        'assigned_to'     => $lead->assigned_to ?? $user->id,
                        'subject_type'    => Lead::class,
                        'subject_id'      => $lead->id,
                        'type'            => $activityType,
                        'title'           => $title,
                        'due_at'          => \Carbon\Carbon::parse($scheduleAt),
                        'priority'        => 'medium',
                        'is_done'         => false,
                    ]);

                    LeadTimeline::log(
                        $lead,
                        'followup_created',
                        ($isMeeting ? 'Meeting' : 'Follow-up') . ' scheduled for ' . $activity->due_at->format('d M Y, h:i A'),
                        $user->id,
                        ['activity_id' => $activity->id, 'due_at' => $activity->due_at->toIso8601String(), 'type' => $activityType]
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
        ]);

        $orgId  = $this->orgId($request);
        $userId = $request->user()->id;
        $now    = now();
        $rows   = [];

        foreach ($request->input('leads') as $row) {
            $rows[] = array_merge($row, [
                'organization_id' => $orgId,
                'created_by'      => $userId,
                'lead_date'       => $now->toDateString(),
                'created_at'      => $now,
                'updated_at'      => $now,
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

        $request->validate([
            'pipeline_id' => ['required', 'integer', 'exists:pipelines,id'],
            'stage_id'    => ['required', 'integer', 'exists:stages,id'],
            'title'       => ['nullable', 'string', 'max:191'],
            'value'       => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = $request->user();

        $deal = \App\Models\Deal::create([
            'organization_id' => $this->orgId($request),
            'lead_id'         => $lead->id,
            'created_by'      => $user->id,
            'assigned_to'     => $lead->assigned_to, // inherit assignee from the lead
            'pipeline_id'     => $request->input('pipeline_id'),
            'stage_id'        => $request->input('stage_id'),
            'title'           => $request->input('title', "Deal — {$lead->full_name}"),
            'value'           => $request->input('value'),
            'status'          => 'open',
        ]);

        // Update lead status to converted
        $lead->update(['status' => 'converted']);

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
            "Status changed from «{$lead->getOriginal('status')}» to «converted» (deal created)",
            $user->id,
            ['from' => $lead->getOriginal('status'), 'to' => 'converted']
        );

        return response()->json([
            'data'    => $deal->load(['stage', 'pipeline']),
            'message' => 'Lead converted to deal successfully.',
        ], 201);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function authorizeOrg(Request $request, Lead $lead): void
    {
        if ($lead->organization_id !== $this->orgId($request)) {
            abort(404);
        }
    }
}
