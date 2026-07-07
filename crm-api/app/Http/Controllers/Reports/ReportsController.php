<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orgId      = $request->user()->organization_id;
        $assignedTo = $request->input('assigned_to');

        // ── 1. Lead conversion funnel ─────────────────────────────────────────
        $funnelRaw = Lead::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $statuses = ['new', 'contacted', 'qualified', 'converted', 'unqualified', 'lost'];
        $funnel   = collect($statuses)->map(fn($s) => [
            'status' => $s,
            'count'  => (int) ($funnelRaw[$s]->count ?? 0),
        ])->values();

        // ── 2. Lead source breakdown ──────────────────────────────────────────
        $leadSources = Lead::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->whereNotNull('source')
            ->where('source', '!=', '')
            ->selectRaw('source, COUNT(*) as count')
            ->groupBy('source')
            ->orderByDesc('count')
            ->get()
            ->map(fn($r) => ['source' => $r->source, 'count' => (int) $r->count]);

        // ── 3. Team leaderboard (won deals this month, org-wide for managers) ─
        $leaderboard = Deal::where('organization_id', $orgId)
            ->where('status', 'won')
            ->whereMonth('closed_at', now()->month)
            ->whereYear('closed_at', now()->year)
            ->whereNotNull('assigned_to')
            ->with('assignedTo:id,name')
            ->selectRaw('assigned_to, COUNT(*) as deals_won, SUM(value) as revenue')
            ->groupBy('assigned_to')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'user_id'    => $r->assigned_to,
                'name'       => $r->assignedTo?->name ?? 'Unknown',
                'deals_won'  => (int) $r->deals_won,
                'revenue'    => (float) $r->revenue,
            ]);

        // ── 4. Lost reason analysis (leads + deals combined) ─────────────────
        $leadLostReasons = Lead::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->where('status', 'lost')
            ->whereNotNull('lost_reason')
            ->where('lost_reason', '!=', '')
            ->selectRaw('lost_reason, COUNT(*) as count')
            ->groupBy('lost_reason')
            ->get()
            ->map(fn($r) => ['lost_reason' => $r->lost_reason, 'count' => (int) $r->count]);

        $dealLostReasons = Deal::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->where('status', 'lost')
            ->whereNotNull('lost_reason')
            ->where('lost_reason', '!=', '')
            ->selectRaw('lost_reason, COUNT(*) as count')
            ->groupBy('lost_reason')
            ->get()
            ->map(fn($r) => ['lost_reason' => $r->lost_reason, 'count' => (int) $r->count]);

        $lostReasons = $leadLostReasons->concat($dealLostReasons)
            ->groupBy('lost_reason')
            ->map(fn($group, $reason) => [
                'lost_reason' => $reason,
                'count'       => $group->sum('count'),
            ])
            ->values()
            ->sortByDesc('count')
            ->values();

        // ── 5. Average deal cycle (days from created_at → closed_at) ─────────
        $avgCycle = Deal::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->whereIn('status', ['won', 'lost'])
            ->whereNotNull('closed_at')
            ->selectRaw('status, AVG(DATEDIFF(closed_at, created_at)) as avg_days, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->map(fn($r) => [
                'status'   => $r->status,
                'avg_days' => round((float) $r->avg_days, 1),
                'count'    => (int) $r->count,
            ]);

        return response()->json([
            'data' => [
                'funnel'       => $funnel,
                'lead_sources' => $leadSources,
                'leaderboard'  => $leaderboard,
                'lost_reasons' => $lostReasons,
                'avg_cycle'    => $avgCycle,
            ],
        ]);
    }
}
