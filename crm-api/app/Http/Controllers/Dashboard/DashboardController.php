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

        $overdueActs = Activity::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->where('is_done', false)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();

        // Follow-ups due today whose scheduled time has not passed yet
        $dueTodayActs = Activity::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->where('is_done', false)
            ->whereNotNull('due_at')
            ->where('due_at', '>=', now())
            ->where('due_at', '<=', now()->endOfDay())
            ->count();

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

        // ── Reminders (overdue + due within 7 days, not done) ────────────────
        $reminders = Activity::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->where('is_done', false)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addDays(7))
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
                'recent_leads'        => LeadResource::collection($recentLeads),
                'recent_deals'        => DealResource::collection($recentDeals),
                'reminders'           => ActivityResource::collection($reminders),
                'target_progress'     => $targetProgress,
                'charts' => [
                    'leads_trend'    => $leadsTrend,
                    'deals_by_stage' => $dealsByStage,
                    'revenue_trend'  => $revenueTrend,
                ],
            ],
        ]);
    }
}
