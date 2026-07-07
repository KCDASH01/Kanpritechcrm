<?php

namespace App\Http\Controllers\Activity;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activity\ActivityRequest;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    private const MORPH_MAP = [
        'lead' => \App\Models\Lead::class,
        'deal' => \App\Models\Deal::class,
    ];

    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    // ── GET /api/activities ───────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Activity::where('organization_id', $this->orgId($request))
                         ->with(['assignedTo', 'createdBy']);

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($request->boolean('overdue')) {
            $query->where('is_done', false)
                  ->whereNotNull('due_at')
                  ->where('due_at', '<', now());
        }

        if ($request->has('is_done')) {
            $query->where('is_done', $request->boolean('is_done'));
        }

        if ($subjectType = $request->input('subject_type')) {
            $morphClass = self::MORPH_MAP[$subjectType] ?? null;
            if ($morphClass) {
                $query->where('subject_type', $morphClass);
            }
        }

        if ($subjectId = $request->input('subject_id')) {
            $query->where('subject_id', $subjectId);
        }

        if ($assignedTo = $request->input('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }

        if ($from = $request->input('date_from')) {
            $query->where('due_at', '>=', \Carbon\Carbon::parse($from)->startOfDay());
        }

        if ($to = $request->input('date_to')) {
            $query->where('due_at', '<=', \Carbon\Carbon::parse($to)->endOfDay());
        }

        $query->orderBy(
            $request->input('sort_by', 'due_at'),
            $request->input('sort_dir', 'asc') === 'asc' ? 'asc' : 'desc'
        );

        $activities = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => ActivityResource::collection($activities),
            'meta' => [
                'total'        => $activities->total(),
                'per_page'     => $activities->perPage(),
                'current_page' => $activities->currentPage(),
                'last_page'    => $activities->lastPage(),
            ],
        ]);
    }

    // ── POST /api/activities ──────────────────────────────────────────────────

    public function store(ActivityRequest $request): JsonResponse
    {
        $validated    = $request->validated();
        $morphClass   = isset($validated['subject_type'])
            ? (self::MORPH_MAP[$validated['subject_type']] ?? null)
            : null;

        $activity = Activity::create([
            'organization_id' => $this->orgId($request),
            'created_by'      => $request->user()->id,
            'assigned_to'     => $validated['assigned_to'] ?? $request->user()->id,
            'subject_type'    => $morphClass,
            'subject_id'      => $validated['subject_id'] ?? null,
            'type'            => $validated['type'],
            'title'           => $validated['title'],
            'description'     => $validated['description'] ?? null,
            'due_at'          => $validated['due_at'] ?? null,
            'priority'        => $validated['priority'] ?? 'medium',
            'is_done'         => false,
        ]);

        // Notify assignee if different from creator
        $assigneeId = $activity->assigned_to;
        if ($assigneeId && $assigneeId !== $request->user()->id) {
            Notification::notify(
                $this->orgId($request),
                $assigneeId,
                'activity_assigned',
                'New Activity Assigned',
                "{$request->user()->name} assigned \"{$activity->title}\" to you",
                ['activity_id' => $activity->id],
                '/activities'
            );
        }

        return response()->json([
            'data'    => new ActivityResource($activity->load(['assignedTo', 'createdBy'])),
            'message' => 'Activity created successfully.',
        ], 201);
    }

    // ── GET /api/activities/{activity} ────────────────────────────────────────

    public function show(Request $request, Activity $activity): JsonResponse
    {
        $this->authorizeOrg($request, $activity);

        return response()->json([
            'data' => new ActivityResource($activity->load(['assignedTo', 'createdBy'])),
        ]);
    }

    // ── PUT /api/activities/{activity} ────────────────────────────────────────

    public function update(ActivityRequest $request, Activity $activity): JsonResponse
    {
        $this->authorizeOrg($request, $activity);

        $validated  = $request->validated();
        $morphClass = isset($validated['subject_type'])
            ? (self::MORPH_MAP[$validated['subject_type']] ?? null)
            : null;

        $activity->update(array_merge($validated, ['subject_type' => $morphClass]));

        return response()->json([
            'data'    => new ActivityResource($activity->fresh(['assignedTo', 'createdBy'])),
            'message' => 'Activity updated successfully.',
        ]);
    }

    // ── DELETE /api/activities/{activity} ─────────────────────────────────────

    public function destroy(Request $request, Activity $activity): JsonResponse
    {
        $this->authorizeOrg($request, $activity);

        $activity->delete();

        return response()->json(['message' => 'Activity deleted successfully.']);
    }

    // ── PATCH /api/activities/{activity}/done ─────────────────────────────────

    public function markDone(Request $request, Activity $activity): JsonResponse
    {
        $this->authorizeOrg($request, $activity);

        $activity->update([
            'is_done'      => true,
            'completed_at' => now(),
        ]);

        return response()->json([
            'data'    => new ActivityResource($activity->fresh(['assignedTo'])),
            'message' => 'Activity marked as done.',
        ]);
    }

    private function authorizeOrg(Request $request, Activity $activity): void
    {
        if ($activity->organization_id !== $this->orgId($request)) {
            abort(404);
        }
    }
}
