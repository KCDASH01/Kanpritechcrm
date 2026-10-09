<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\DealResource;
use App\Http\Resources\LeadResource;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\Lead;
use App\Models\User;
use App\Services\RevenueRecognitionService;
use App\Services\TargetProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /** @var list<string> */
    private const LEAD_SUBJECT_TYPES = ['lead', Lead::class];

    public function __construct(
        private readonly RevenueRecognitionService $revenue,
        private readonly TargetProgressService $targets,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'assigned_to' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $orgId = (int) $request->user()->organization_id;
        $assignedTo = $this->resolveAssignedTo($request);

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
            ->with(['stage', 'assignedTo', 'client', 'department'])
            ->latest()
            ->limit(5)
            ->get();

        $recentDeals = Deal::where('organization_id', $orgId)
            ->when($assignedTo, fn($q) => $q->where('assigned_to', $assignedTo))
            ->with(['stage', 'lead', 'client', 'assignedTo', 'department'])
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

        $trendStart = now()->subDays(29)->startOfDay();
        $trendEnd = now()->endOfDay();
        $revenueTrend = $this->revenue->dailyTrend($orgId, $assignedTo ? (int) $assignedTo : null, $trendStart, $trendEnd);
        $revenueBreakdown = $this->revenue->breakdown($orgId, $assignedTo ? (int) $assignedTo : null);

        $timezone = $request->user()->organization?->timezone ?: config('app.timezone');
        $targetDate = Carbon::now($timezone)->startOfDay();
        [$periodStart, $periodEnd, $periodLabel] = $this->targets->period($request->input('month'));
        if ($assignedTo) {
            $activeTarget = $this->targets->activeTarget($orgId, (int) $assignedTo, $targetDate);
            $targetProgress = $activeTarget
                ? $this->targets->progressForTarget($activeTarget)
                : $this->targets->progress($orgId, (int) $assignedTo, $periodStart, $periodEnd);
        } else {
            $targetProgress = $this->targets->activeTeamProgress($orgId, $targetDate);
        }
        $periodStart = (string) $targetProgress['period_start'];
        $periodEnd = (string) ($targetProgress['period_end'] ?? $periodEnd);
        $periodLabel = (string) ($targetProgress['period_label'] ?? $periodLabel);
        $collectionSummary = $this->revenue->collectionSummary($orgId, $assignedTo, now());
        $teamMembers = $request->user()->isAdmin()
            ? User::query()->where('organization_id', $orgId)->where('is_active', true)->where('role', '!=', 'owner')
                ->orderBy('name')->get(['id', 'name'])->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name])->values()
            : collect();

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
                    ...$revenueBreakdown,
                ],
                'recent_leads'        => LeadResource::collection($recentLeads)->resolve(),
                'recent_deals'        => DealResource::collection($recentDeals)->resolve(),
                'reminders'           => ActivityResource::collection($reminders)->resolve(),
                'target_progress'     => $targetProgress,
                'performance' => [
                    'period_start' => $periodStart,
                    'period_label' => $periodLabel,
                    'selected_user_id' => $assignedTo,
                    'target' => $targetProgress,
                    'revenue' => $collectionSummary,
                    'team_members' => $teamMembers,
                ],
                'charts' => [
                    'leads_trend'    => $leadsTrend,
                    'deals_by_stage' => $dealsByStage,
                    'revenue_trend'  => $revenueTrend,
                ],
            ],
        ]);
    }

    public function performanceDetails(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:sales,target_collections,collections_month,collections_all,receivables'],
            'assigned_to' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $orgId = (int) $request->user()->organization_id;
        $assignedTo = $this->resolveAssignedTo($request);
        $timezone = $request->user()->organization?->timezone ?: config('app.timezone');
        $targetDate = Carbon::now($timezone)->startOfDay();
        $targetProgress = $assignedTo
            ? (($target = $this->targets->activeTarget($orgId, (int) $assignedTo, $targetDate)) ? $this->targets->progressForTarget($target) : null)
            : $this->targets->activeTeamProgress($orgId, $targetDate);
        [$periodStart, $periodEnd, $periodLabel] = $this->targets->period($data['month'] ?? null);
        if ($targetProgress && $targetProgress['has_target']) {
            $periodStart = (string) $targetProgress['period_start'];
            $periodEnd = (string) ($targetProgress['period_end'] ?? $periodEnd);
            $periodLabel = (string) ($targetProgress['period_label'] ?? $periodLabel);
        }
        $page = max(1, $request->integer('page', 1));
        $perPage = 50;

        if ($data['type'] === 'sales') {
            $paginator = $this->targets->activeSalesDetailsQuery($orgId, $assignedTo, $targetDate)
                ->with(['client', 'lead', 'assignedTo', 'recurringBusiness'])
                ->withSum('payments', 'amount')
                ->latest('closed_at')
                ->paginate($perPage, ['*'], 'page', $page);
            $rows = $paginator->getCollection()->map(fn (Deal $deal) => [
                    'id' => $deal->id,
                    'deal_id' => $deal->id,
                    'deal' => $deal->title,
                    'client' => $deal->client?->company ?: $deal->client?->full_name,
                    'lead' => $deal->lead?->full_name,
                    'service' => $deal->service_type,
                    'employee' => $deal->assignedTo?->name,
                    'deal_value' => (float) $deal->value,
                    'deal_status' => $deal->status,
                    'date' => $deal->closed_at?->toDateString(),
                    'business_type' => $deal->business_type,
                    'market_type' => $deal->market_type,
                    'collected' => (float) ($deal->payments_sum_amount ?? 0),
                    'outstanding' => $this->revenue->dealReceivable($deal, now()),
                    'currency' => $deal->currency ?: 'INR',
                ]);
            $meta = ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()];
        } elseif (in_array($data['type'], ['target_collections', 'collections_month', 'collections_all'], true)) {
            $rowsQuery = $data['type'] === 'target_collections'
                ? $this->targets->activeCollectionDetailsQuery($orgId, $assignedTo, $targetDate)
                : $this->revenue->collectionDetailsQuery(
                    $orgId,
                    $assignedTo,
                    $data['type'] === 'collections_month' ? $periodStart : null,
                    $data['type'] === 'collections_month' ? $periodEnd : null,
                );
            $paginator = $rowsQuery->with(['deal' => fn ($deal) => $deal
                    ->withSum('payments', 'amount')
                    ->with(['client', 'lead', 'assignedTo', 'recurringBusiness'])])
                ->latest('payment_date')
                ->paginate($perPage, ['*'], 'page', $page);
            $rows = $paginator->getCollection()->map(fn (DealPayment $payment) => [
                    'id' => $payment->id,
                    'date' => $payment->payment_date?->toDateString(),
                    'client' => $payment->deal?->client?->company ?: $payment->deal?->client?->full_name,
                    'deal' => $payment->deal?->title,
                    'deal_id' => $payment->deal_id,
                    'lead' => $payment->deal?->lead?->full_name,
                    'service' => $payment->deal?->service_type,
                    'employee' => $payment->deal?->assignedTo?->name,
                    'amount' => (float) $payment->amount,
                    'deal_value' => (float) ($payment->deal?->value ?? 0),
                    'total_collected' => (float) ($payment->deal?->payments_sum_amount ?? 0),
                    'outstanding' => $payment->deal ? $this->revenue->dealReceivable($payment->deal, now()) : 0,
                    'payment_method' => $payment->payment_mode,
                    'reference' => $payment->txn_or_utr_number,
                    'currency' => $payment->deal?->currency ?: 'INR',
                    'status' => $payment->deal?->status,
                ]);
            $meta = ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()];
        } else {
            $allRows = $this->revenue->receivableRows($orgId, $assignedTo, now());
            $rows = $allRows->slice(($page - 1) * $perPage, $perPage)->values();
            $meta = ['current_page' => $page, 'last_page' => max(1, (int) ceil($allRows->count() / $perPage)), 'total' => $allRows->count()];
        }

        return response()->json(['data' => [
            'type' => $data['type'],
            'period_label' => $periodLabel,
            'rows' => $rows,
            'meta' => $meta,
        ]]);
    }

    private function resolveAssignedTo(Request $request): ?int
    {
        $user = $request->user();
        if ($user->isEmployee()) return (int) $user->id;
        if (! $request->filled('assigned_to')) return null;

        return (int) User::query()
            ->where('organization_id', $user->organization_id)
            ->where('is_active', true)
            ->where('role', '!=', 'owner')
            ->findOrFail($request->integer('assigned_to'))
            ->id;
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
