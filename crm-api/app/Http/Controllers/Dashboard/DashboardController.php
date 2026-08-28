<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SalesTarget\SalesTargetController;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\DealResource;
use App\Http\Resources\LeadResource;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\SalesTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** @var list<string> */
    private const LEAD_SUBJECT_TYPES = ['lead', Lead::class];

    public function index(Request $request): JsonResponse
    {
        $orgId      = $request->user()->organization_id;
        $assignedTo = $request->input('assigned_to'); // null = org-wide (manager), set = personal (team member)

        // ── Counts ────────────────────────────────────────────────────────────
        $totalLeads = Lead::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->count();

        $newLeads = Lead::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->where('status', 'new')
            ->count();

        $openDeals = Deal::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->where('status', 'open')
            ->count();

        $wonDeals = Deal::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->where('status', 'won')
            ->count();

        $dealValue = Deal::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->where('status', 'open')
            ->sum('value');

        // Follow-up + meeting leads with a pending schedule (matches FollowUp / Meetings pages)
        $overdueActs  = $this->countOverdueLeads($orgId, $assignedTo);
        $dueTodayActs = $this->countDueTodayLeads($orgId, $assignedTo);

        // ── Recent data ───────────────────────────────────────────────────────
        $recentLeads = Lead::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->with(['stage', 'assignedTo'])
            ->latest()
            ->limit(5)
            ->get();

        $recentDeals = Deal::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->with(['stage', 'lead', 'assignedTo'])
            ->latest()
            ->limit(5)
            ->get();

        // ── Reminders (follow-up / meeting leads only, pending, due within 7 days) ──
        $scheduledLeadIds = Lead::where('organization_id', $orgId)
            ->whereIn('status', ['followup', 'meeting'])
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->pluck('id');

        $reminders = $scheduledLeadIds->isEmpty()
            ? collect()
            : Activity::where('organization_id', $orgId)
                ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
                ->where('is_done', false)
                ->whereNotNull('due_at')
                ->where('due_at', '<=', now()->addDays(7))
                ->where(function ($q) use ($scheduledLeadIds) {
                    $q->where(function ($inner) use ($scheduledLeadIds) {
                        foreach (self::LEAD_SUBJECT_TYPES as $type) {
                            $inner->orWhere(function ($q2) use ($type, $scheduledLeadIds) {
                                $q2->where('subject_type', $type)
                                   ->whereIn('subject_id', $scheduledLeadIds);
                            });
                        }
                    });
                })
                ->whereRaw(
                    "activities.id = (
                        SELECT a2.id FROM activities a2
                        INNER JOIN leads l ON l.id = a2.subject_id
                        WHERE a2.subject_id = activities.subject_id
                          AND a2.due_at IS NOT NULL
                          AND a2.deleted_at IS NULL
                          AND a2.type IN ('task', 'meeting')
                          AND l.status IN ('followup', 'meeting')
                          AND (a2.subject_type = ? OR a2.subject_type = ?)
                        ORDER BY a2.is_done ASC, a2.updated_at DESC, a2.created_at DESC
                        LIMIT 1
                    )",
                    [self::LEAD_SUBJECT_TYPES[0], Lead::class]
                )
                ->orderBy('due_at')
                ->limit(10)
                ->with(['assignedTo'])
                ->get();

        // ── Chart data ────────────────────────────────────────────────────────
        // Leads created per day — last 30 days
        $leadsTrend = Lead::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'count' => (int) $r->count]);

        // Open deals grouped by stage
        $dealsByStage = Deal::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->where('status', 'open')
            ->with('stage:id,name,color')
            ->selectRaw('stage_id, COUNT(*) as count, SUM(value) as total_value')
            ->groupBy('stage_id')
            ->get()
            ->map(fn($r) => [
                'stage_id'    => $r->stage_id,
                'count'       => (int) $r->count,
                'total_value' => (float) $r->total_value,
                'stage'       => $r->stage ? ['id' => $r->stage->id, 'name' => $r->stage->name, 'color' => $r->stage->color] : null,
            ]);

        // Revenue (won deals) per day — last 30 days
        $revenueTrend = Deal::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->where('status', 'won')
            ->where('updated_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(updated_at) as date, SUM(value) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'revenue' => (float) $r->revenue]);

        // ── Sales target progress (only for team member / personal view) ────────
        $targetProgress = null;
        if ($assignedTo) {
            $periodStart = now()->startOfMonth()->toDateString();
            $periodEnd   = now()->endOfMonth()->toDateString();

            $target = SalesTarget::where('organization_id', $orgId)
                ->where('user_id', $assignedTo)
                ->where('period_start', $periodStart)
                ->first();

            if ($target) {
                $achieved = SalesTargetController::calcAchieved($orgId, (int) $assignedTo, $periodStart, $periodEnd);
                $targetProgress = [
                    'target_amount'     => (float) $target->target_amount,
                    'receivable_amount' => (float) $target->receivable_amount,
                    'received_amount'   => $target->received_amount !== null ? (float) $target->received_amount : null,
                    'achieved_amount'   => $achieved,
                    'target_id'         => $target->id,
                ];
            }
        }

        return response()->json([
            'data' => [
                'stats' => [
                    'total_leads'        => $totalLeads,
                    'new_leads'          => $newLeads,
                    'open_deals'         => $openDeals,
                    'won_deals'          => $wonDeals,
                    'deal_value'           => round($dealValue, 2),
                    'due_today_activities' => $dueTodayActs,
                    'overdue_activities'   => $overdueActs,
                ],
                'recent_leads'        => LeadResource::collection($recentLeads)->resolve(),
                'recent_deals'        => DealResource::collection($recentDeals)->resolve(),
                'reminders'           => ActivityResource::collection($reminders)->resolve(),
                'target_progress'     => $targetProgress,
                'charts' => [
                    'leads_trend'    => $leadsTrend,
                    'deals_by_stage' => $dealsByStage,
                    'revenue_trend'  => $revenueTrend,
                ],
            ],
        ]);
    }

    private function countDueTodayLeads(string $orgId, ?int $assignedTo): int
    {
        $dueToday = fn ($q) => $q->where('a.is_done', false)
            ->where('a.due_at', '>=', now())
            ->where('a.due_at', '<=', now()->endOfDay());

        return $this->countScheduledLeads($orgId, $assignedTo, 'followup', 'task', $dueToday)
            + $this->countScheduledLeads($orgId, $assignedTo, 'meeting', 'meeting', $dueToday);
    }

    private function countOverdueLeads(string $orgId, ?int $assignedTo): int
    {
        $overdue = fn ($q) => $q->where('a.is_done', false)
            ->where('a.due_at', '<', now());

        return $this->countScheduledLeads($orgId, $assignedTo, 'followup', 'task', $overdue)
            + $this->countScheduledLeads($orgId, $assignedTo, 'meeting', 'meeting', $overdue);
    }

    /** @param callable(\Illuminate\Database\Query\Builder): void $dueConstraints */
    private function countScheduledLeads(
        string $orgId,
        ?int $assignedTo,
        string $leadStatus,
        string $activityType,
        callable $dueConstraints
    ): int {
        $subjectTypes = self::LEAD_SUBJECT_TYPES;
        $leadClass    = Lead::class;

        return Lead::where('organization_id', $orgId)
            ->where('status', $leadStatus)
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->whereExists(function ($sub) use ($activityType, $dueConstraints, $subjectTypes, $leadClass) {
                $sub->selectRaw('1')
                    ->from('activities as a')
                    ->whereColumn('a.subject_id', 'leads.id')
                    ->where(function ($q) use ($subjectTypes) {
                        foreach ($subjectTypes as $type) {
                            $q->orWhere('a.subject_type', $type);
                        }
                    })
                    ->where('a.type', $activityType)
                    ->whereNotNull('a.due_at')
                    ->whereNull('a.deleted_at')
                    ->whereRaw(
                        "a.id = (
                            SELECT a2.id FROM activities a2
                            WHERE a2.subject_id = leads.id
                              AND a2.type = ?
                              AND a2.due_at IS NOT NULL
                              AND a2.deleted_at IS NULL
                              AND (a2.subject_type = ? OR a2.subject_type = ?)
                            ORDER BY a2.is_done ASC, a2.updated_at DESC, a2.created_at DESC
                            LIMIT 1
                        )",
                        [$activityType, $subjectTypes[0], $leadClass]
                    );

                $dueConstraints($sub);
            })
            ->count();
    }
}
