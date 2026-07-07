<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Organization;
use App\Models\SalesTarget;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SalesTargetController extends Controller
{
    // ── GET /sales-targets ────────────────────────────────────────────────────
    // List all orgs / employees with targets for selected month

    public function index(Request $request)
    {
        $orgId      = $request->input('org');
        $monthParam = $request->input('month', now()->format('Y-m'));

        [$year, $month] = explode('-', $monthParam);
        $periodStart = Carbon::createFromDate((int)$year, (int)$month, 1)->toDateString();
        $periodEnd   = Carbon::createFromDate((int)$year, (int)$month, 1)->endOfMonth()->toDateString();

        $query = SalesTarget::with(['user:id,name,email', 'organization:id,name'])
            ->where('period_start', $periodStart);

        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $rows = $query->get()->map(function (SalesTarget $t) use ($periodEnd) {
            $achieved = $this->calcAchieved($t->organization_id, $t->user_id, $t->period_start->toDateString(), $periodEnd);
            return [
                'target'    => $t,
                'user'      => $t->user,
                'org'       => $t->organization,
                'achieved'  => $achieved,
                'sales_pct' => $t->target_amount > 0 ? round(($achieved / $t->target_amount) * 100, 1) : 0,
                'coll_pct'  => $t->receivable_amount > 0 && $t->received_amount !== null
                    ? round(($t->received_amount / $t->receivable_amount) * 100, 1)
                    : null,
            ];
        });

        $orgs = Organization::orderBy('name')->get(['id', 'name']);

        // Load all users grouped by org_id for the "Set Target" modal
        $usersByOrg = User::whereNotNull('organization_id')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'organization_id'])
            ->groupBy('organization_id');

        $success = session('success');

        return view('sales-targets.index', compact('rows', 'orgs', 'monthParam', 'orgId', 'usersByOrg', 'success'));
    }

    // ── POST /sales-targets ───────────────────────────────────────────────────
    // Super admin creates / updates a target for any employee

    public function store(Request $request)
    {
        $data = $request->validate([
            'organization_id'  => ['required', 'integer', 'exists:organizations,id'],
            'user_id'          => ['required', 'integer', 'exists:users,id'],
            'target_amount'    => ['required', 'numeric', 'min:0'],
            'receivable_amount'=> ['required', 'numeric', 'min:0'],
            'period_start'     => ['required', 'date'],
        ]);

        // Normalise period_start to first of month
        $data['period_start'] = Carbon::parse($data['period_start'])->startOfMonth()->toDateString();

        SalesTarget::updateOrCreate(
            [
                'organization_id' => $data['organization_id'],
                'user_id'         => $data['user_id'],
                'period_start'    => $data['period_start'],
            ],
            [
                'target_amount'     => $data['target_amount'],
                'receivable_amount' => $data['receivable_amount'],
            ]
        );

        $month = Carbon::parse($data['period_start'])->format('Y-m');

        return redirect()
            ->route('sales-targets.index', ['month' => $month, 'org' => $data['organization_id']])
            ->with('success', 'Sales target saved successfully.');
    }

    // ── PUT /sales-targets/{salesTarget} ──────────────────────────────────────
    // Inline edit from the employee show page

    public function update(Request $request, SalesTarget $salesTarget)
    {
        $data = $request->validate([
            'target_amount'    => ['required', 'numeric', 'min:0'],
            'receivable_amount'=> ['required', 'numeric', 'min:0'],
        ]);

        $salesTarget->update($data);

        return redirect()->back()->with('success', 'Target updated successfully.');
    }

    // ── GET /sales-targets/{org}/{user} ───────────────────────────────────────
    // Per-employee 6-month analysis — mirrors what the team member sees on their dashboard

    public function show(Request $request, Organization $org, User $user)
    {
        // Last 6 months
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = now()->subMonths($i)->startOfMonth()->toDateString();
        }

        $targetsCollection = SalesTarget::where('organization_id', $org->id)
            ->where('user_id', $user->id)
            ->whereIn('period_start', $months)
            ->orderBy('period_start')
            ->get()
            ->keyBy(fn($t) => $t->period_start->toDateString());

        $history = array_map(function (string $periodStart) use ($org, $user, $targetsCollection) {
            $periodEnd = Carbon::parse($periodStart)->endOfMonth()->toDateString();
            $target    = $targetsCollection->get($periodStart);
            $achieved  = $this->calcAchieved($org->id, $user->id, $periodStart, $periodEnd);

            return [
                'period_start'      => $periodStart,
                'month_label'       => Carbon::parse($periodStart)->format('M Y'),
                'target_id'         => $target?->id,
                'target_amount'     => $target ? (float) $target->target_amount : 0,
                'receivable_amount' => $target ? (float) $target->receivable_amount : 0,
                'received_amount'   => $target && $target->received_amount !== null ? (float) $target->received_amount : null,
                'achieved_amount'   => $achieved,
                'sales_pct'         => ($target && (float)$target->target_amount > 0)
                    ? round(($achieved / (float)$target->target_amount) * 100, 1) : 0,
                'coll_pct'          => ($target && (float)$target->receivable_amount > 0 && $target->received_amount !== null)
                    ? round((float)$target->received_amount / (float)$target->receivable_amount * 100, 1) : null,
            ];
        }, $months);

        // Chart data for Chart.js
        $labels        = array_column($history, 'month_label');
        $salesTargets  = array_column($history, 'target_amount');
        $salesAchieved = array_column($history, 'achieved_amount');
        $collTargets   = array_column($history, 'receivable_amount');
        $collReceived  = array_map(fn($h) => $h['received_amount'] ?? 0, $history);

        $success = session('success');

        return view('sales-targets.show', compact(
            'org', 'user', 'history',
            'labels', 'salesTargets', 'salesAchieved', 'collTargets', 'collReceived',
            'success'
        ));
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function calcAchieved(int $orgId, int $userId, string $from, string $to): float
    {
        return (float) Deal::where('organization_id', $orgId)
            ->where('assigned_to', $userId)
            ->where('status', 'won')
            ->whereBetween('closed_at', [$from, $to])
            ->sum('value');
    }
}
