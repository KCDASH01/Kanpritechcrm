<?php

namespace App\Http\Controllers\Deal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deal\DealRequest;
use App\Http\Resources\DealResource;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Notification;
use App\Models\Stage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DealController extends Controller
{
    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    // ── GET /api/deals ────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Deal::where('organization_id', $this->orgId($request))
                     ->with(['stage', 'pipeline', 'lead', 'client', 'department', 'assignedTo', 'createdBy'])
                     ->withSum('payments', 'amount')
                     ->withCount('payments');

        if ($request->user()->isEmployee()) {
            $query->where('assigned_to', $request->user()->id);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhereHas('lead', function ($lq) use ($search) {
                      $lq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('company', 'like', "%{$search}%");
                  });
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        foreach (['client_type', 'business_type', 'market_type', 'service_type'] as $field) {
            if ($value = $request->input($field)) $query->where($field, $value);
        }

        if ($stageId = $request->input('stage_id')) {
            $query->where('stage_id', $stageId);
        }

        if ($pipelineId = $request->input('pipeline_id')) {
            $query->where('pipeline_id', $pipelineId);
        }

        if ($assignedTo = $request->input('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }

        $sortBy  = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $allowed = ['created_at', 'title', 'value', 'expected_close_date', 'status', 'probability'];

        if (in_array($sortBy, $allowed)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $deals = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => DealResource::collection($deals),
            'meta' => [
                'total'        => $deals->total(),
                'per_page'     => $deals->perPage(),
                'current_page' => $deals->currentPage(),
                'last_page'    => $deals->lastPage(),
            ],
        ]);
    }

    // ── POST /api/deals ───────────────────────────────────────────────────────

    public function store(DealRequest $request): JsonResponse
    {
        $user = $request->user();

        // Plan deal limits
        $org = $user->organization;
        if ($org?->isEnterprisePlan()) {
            // Enterprise: unlimited — no check
        } elseif ($org?->isBusinessPlan()) {
            $count = Deal::where('organization_id', $this->orgId($request))->count();
            if ($count >= 2000) {
                return response()->json([
                    'message' => 'You have reached the 2,000-deal limit on the Business plan. Upgrade to Enterprise for unlimited deals.',
                    'code'    => 'LIMIT_REACHED',
                ], 403);
            }
        } else {
            $count = Deal::where('organization_id', $this->orgId($request))->count();
            if ($count >= 50) {
                return response()->json([
                    'message' => 'You have reached the 50-deal limit on the Free plan. Upgrade to Business or Enterprise.',
                    'code'    => 'LIMIT_REACHED',
                ], 403);
            }
        }

        $deal = Deal::create(array_merge($request->validated(), [
            'organization_id' => $this->orgId($request),
            'created_by'      => $user->id,
        ]));

        // If this deal was created as a pipeline hand-off, guard against duplicates
        // then stamp the source deal so the button disappears permanently.
        $sourceDealId = $request->input('source_deal_id');
        if ($sourceDealId) {
            $sourceDeal = \App\Models\Deal::where('id', (int) $sourceDealId)
                ->where('organization_id', $this->orgId($request))
                ->first();

            // Reject if the source deal was already handed off
            if ($sourceDeal && $sourceDeal->handed_off_at) {
                $deal->delete(); // roll back the deal we just created
                return response()->json([
                    'message' => 'This deal has already been handed off to another pipeline.',
                ], 409);
            }

            // Stamp the source deal as handed off
            DB::table('deals')
                ->where('id', (int) $sourceDealId)
                ->where('organization_id', $this->orgId($request))
                ->update(['handed_off_at' => now()]);
        }

        return response()->json([
            'data'    => new DealResource($deal->load(['stage', 'pipeline', 'lead', 'client', 'department', 'assignedTo'])),
            'message' => 'Deal created successfully.',
        ], 201);
    }

    // ── GET /api/deals/{deal} ─────────────────────────────────────────────────

    public function show(Request $request, Deal $deal): JsonResponse
    {
        $this->authorizeOrg($request, $deal);

        $deal->loadCount('payments');
        $deal->loadSum('payments', 'amount');
        $deal->load(['stage', 'pipeline', 'lead', 'client', 'department', 'assignedTo', 'createdBy', 'activities', 'notes.createdBy']);

        return response()->json(['data' => new DealResource($deal)]);
    }

    // ── PUT /api/deals/{deal} ─────────────────────────────────────────────────

    public function update(DealRequest $request, Deal $deal): JsonResponse
    {
        $this->authorizeOrg($request, $deal);

        $user       = $request->user();
        $validated  = $request->validated();
        $oldStatus  = $deal->status;
        $newStatus  = $validated['status'] ?? $oldStatus;

        // ── Negotiation price sync ─────────────────────────────────────────────
        // Whenever counter_offer_value is present, always mirror it to `value`
        // so the deal amount reflects the negotiated price everywhere.
        $oldCounter  = (float) ($deal->counter_offer_value ?? 0);
        $newCounter  = isset($validated['counter_offer_value']) && $validated['counter_offer_value'] !== null
                        ? (float) $validated['counter_offer_value']
                        : null;

        if ($newCounter !== null) {
            // Force-write value directly — bypasses dirty-tracking cast quirks
            $validated['value'] = $newCounter;

            // Fire follow-up only when the counter-offer actually changes
            if (abs($newCounter - $oldCounter) > 0.001) {
                $originalQuote = (float) ($deal->original_value ?? $deal->value ?? 0);
                $diff          = $newCounter - $originalQuote;
                $direction     = $diff < 0 ? 'reduced' : 'increased';

                Activity::create([
                    'organization_id' => $deal->organization_id,
                    'created_by'      => $user->id,
                    'assigned_to'     => $deal->assigned_to ?? $user->id,
                    'subject_type'    => 'deal',
                    'subject_id'      => $deal->id,
                    'type'            => 'task',
                    'title'           => 'Follow up: negotiated price ' . $direction
                                        . ' — ₹' . number_format($originalQuote, 0)
                                        . ' → ₹' . number_format($newCounter, 0),
                    'description'     => "Price {$direction} by {$user->name} during negotiation. Confirm updated terms with client and update contract if needed.",
                    'due_at'          => now()->addDay()->setTime(10, 0),
                    'priority'        => 'high',
                    'is_done'         => false,
                ]);
            }
        }

        // ── Guaranteed value write (bypass model cast / dirty-track issues) ───
        // We write the resolved value directly to the DB BEFORE the main update
        // so it is never silently skipped by Eloquent's dirty comparison.
        if ($newCounter !== null) {
            DB::table('deals')->where('id', $deal->id)->update(['value' => $newCounter]);
            $deal->syncOriginal(); // keep the in-memory model consistent
        }

        // When status changes to won/lost via the edit form, auto-move to the
        // matching pipeline stage so the Kanban board reflects the change immediately.
        if ($newStatus !== $oldStatus && in_array($newStatus, ['won', 'lost'])) {
            $colName     = $newStatus === 'won' ? 'is_won' : 'is_lost';
            $targetStage = Stage::where('pipeline_id', $deal->pipeline_id)
                               ->where($colName, true)
                               ->first();
            if ($targetStage) {
                $validated['stage_id']  = $targetStage->id;
            }
            $validated['closed_at'] = now()->toDateString();
        } elseif ($newStatus === 'open' && $oldStatus !== 'open') {
            // Re-opening a deal clears the closed date
            $validated['closed_at'] = null;
        }

        $deal->update($validated);

        // Cascade: deal marked lost → linked lead marked lost
        // (guard in LeadController prevents overwriting a 'converted' lead's status)
        if ($newStatus === 'lost' && $oldStatus !== 'lost' && $deal->lead_id) {
            \App\Models\Lead::where('id', $deal->lead_id)
                ->where('status', '!=', 'lost')
                ->where('status', '!=', 'converted')   // never overwrite converted
                ->update([
                    'status'      => 'lost',
                    'lost_reason' => $deal->fresh()->lost_reason ?? null,
                ]);
        }

        return response()->json([
            'data'    => new DealResource($deal->fresh(['stage', 'pipeline', 'lead', 'client', 'department', 'assignedTo'])),
            'message' => 'Deal updated successfully.',
        ]);
    }

    // ── DELETE /api/deals/{deal} ──────────────────────────────────────────────

    public function destroy(Request $request, Deal $deal): JsonResponse
    {
        $this->authorizeOrg($request, $deal);

        if (!in_array($request->user()->role, ['owner', 'admin'])) {
            return response()->json(['message' => 'Only managers can delete deals.'], 403);
        }

        $deal->delete();

        return response()->json(['message' => 'Deal deleted successfully.']);
    }

    // ── PATCH /api/deals/{deal}/stage ─────────────────────────────────────────

    public function moveStage(Request $request, Deal $deal): JsonResponse
    {
        $this->authorizeOrg($request, $deal);

        $data = $request->validate([
            'stage_id' => ['required', 'integer', 'exists:stages,id'],
        ]);

        $stage = Stage::findOrFail($data['stage_id']);

        $updates = ['stage_id' => $stage->id];

        $oldStatus = $deal->status;

        if ($stage->is_won) {
            $updates['status']    = 'won';
            $updates['closed_at'] = now()->toDateString();
        } elseif ($stage->is_lost) {
            $updates['status']    = 'lost';
            $updates['closed_at'] = now()->toDateString();
        } else {
            $updates['status']    = 'open';
            $updates['closed_at'] = null;
        }

        $deal->update($updates);

        // Cascade: deal dragged to lost stage → linked lead marked lost
        if ($stage->is_lost && $oldStatus !== 'lost' && $deal->lead_id) {
            \App\Models\Lead::where('id', $deal->lead_id)
                ->where('status', '!=', 'lost')
                ->update([
                    'status'      => 'lost',
                    'lost_reason' => $deal->lost_reason ?? null,
                ]);
        }

        // Notify assignee if different from mover
        if ($deal->assigned_to && $deal->assigned_to !== $request->user()->id) {
            Notification::notify(
                $deal->organization_id,
                $deal->assigned_to,
                'deal_stage_changed',
                'Deal Stage Updated',
                "{$request->user()->name} moved \"{$deal->title}\" to {$stage->name}",
                ['deal_id' => $deal->id],
                '/deals'
            );
        }

        return response()->json([
            'data'    => new DealResource($deal->fresh(['stage', 'pipeline'])),
            'message' => 'Deal moved successfully.',
        ]);
    }

    private function authorizeOrg(Request $request, Deal $deal): void
    {
        if ($deal->organization_id !== $this->orgId($request)) {
            abort(404);
        }
        if ($request->user()->isEmployee() && $deal->assigned_to !== $request->user()->id) {
            abort(404);
        }
    }
}
