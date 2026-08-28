<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeadReportResource;
use App\Http\Resources\RevenueReportResource;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\Lead;
use App\Support\ReportExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    /** @var list<string> */
    private const LEAD_SUBJECT_TYPES = ['lead', Lead::class];

    public function index(Request $request): JsonResponse
    {
        $orgId      = $request->user()->organization_id;
        $assignedTo = $request->input('assigned_to');

        $funnelRaw = Lead::where('organization_id', $orgId)
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $statuses = ['new', 'contacted', 'ringing', 'converted', 'important', 'lost'];
        $funnel   = collect($statuses)->map(fn ($s) => [
            'status' => $s,
            'count'  => (int) ($funnelRaw[$s]->count ?? 0),
        ])->values();

        $leadSources = Lead::where('organization_id', $orgId)
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->whereNotNull('source')
            ->where('source', '!=', '')
            ->selectRaw('source, COUNT(*) as count')
            ->groupBy('source')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['source' => $r->source, 'count' => (int) $r->count]);

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
            ->map(fn ($r) => [
                'user_id'   => $r->assigned_to,
                'name'      => $r->assignedTo?->name ?? 'Unknown',
                'deals_won' => (int) $r->deals_won,
                'revenue'   => (float) $r->revenue,
            ]);

        $leadLostReasons = Lead::where('organization_id', $orgId)
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->where('status', 'lost')
            ->whereNotNull('lost_reason')
            ->where('lost_reason', '!=', '')
            ->selectRaw('lost_reason, COUNT(*) as count')
            ->groupBy('lost_reason')
            ->get()
            ->map(fn ($r) => ['lost_reason' => $r->lost_reason, 'count' => (int) $r->count]);

        $dealLostReasons = Deal::where('organization_id', $orgId)
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->where('status', 'lost')
            ->whereNotNull('lost_reason')
            ->where('lost_reason', '!=', '')
            ->selectRaw('lost_reason, COUNT(*) as count')
            ->groupBy('lost_reason')
            ->get()
            ->map(fn ($r) => ['lost_reason' => $r->lost_reason, 'count' => (int) $r->count]);

        $lostReasons = $leadLostReasons->concat($dealLostReasons)
            ->groupBy('lost_reason')
            ->map(fn ($group, $reason) => [
                'lost_reason' => $reason,
                'count'       => $group->sum('count'),
            ])
            ->values()
            ->sortByDesc('count')
            ->values();

        $avgCycle = Deal::where('organization_id', $orgId)
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->whereIn('status', ['won', 'lost'])
            ->whereNotNull('closed_at')
            ->selectRaw('status, AVG(DATEDIFF(closed_at, created_at)) as avg_days, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->map(fn ($r) => [
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

    public function leadReport(Request $request): JsonResponse
    {
        $request->validate([
            'search'      => ['nullable', 'string', 'max:191'],
            'status'      => ['nullable', 'string', 'max:32'],
            'assigned_to' => ['nullable', 'integer'],
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'        => ['nullable', 'integer', 'min:1'],
        ]);

        $orgId = $request->user()->organization_id;
        $base  = $this->leadReportQuery($orgId, $request, $this->resolveReportAssignedTo($request));

        $summary = $this->leadReportSummary(clone $base);

        $leads = (clone $base)
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => [
                'summary' => $summary,
                'rows'    => LeadReportResource::collection($leads->items())->resolve(),
            ],
            'meta' => [
                'total'        => $leads->total(),
                'per_page'     => $leads->perPage(),
                'current_page' => $leads->currentPage(),
                'last_page'    => $leads->lastPage(),
            ],
        ]);
    }

    public function exportLeadReport(Request $request): StreamedResponse
    {
        $request->validate([
            'format'      => ['nullable', 'in:csv,xlsx'],
            'search'      => ['nullable', 'string', 'max:191'],
            'status'      => ['nullable', 'string', 'max:32'],
            'assigned_to' => ['nullable', 'integer'],
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $orgId  = $request->user()->organization_id;
        $format = $request->input('format', 'csv');
        $rows   = $this->leadReportQuery($orgId, $request, $this->resolveReportAssignedTo($request))
            ->orderByDesc('created_at')
            ->get();

        $headers = [
            'Lead Name', 'Company', 'City', 'Contact Person', 'Phone', 'Email',
            'Assigned Member', 'Lead Source', 'Lead Status',
            'Created Date', 'Last Updated', 'Follow-up Date',
        ];

        $data = $rows->map(fn (Lead $lead) => [
            $lead->full_name,
            $lead->company,
            $lead->city,
            $lead->full_name,
            $lead->phone,
            $lead->email,
            $lead->assignedTo?->name,
            $lead->source,
            $lead->status,
            $lead->created_at?->format('Y-m-d H:i'),
            $lead->updated_at?->format('Y-m-d H:i'),
            $lead->follow_up_date ? Carbon::parse($lead->follow_up_date)->format('Y-m-d H:i') : '',
        ]);

        $filename = 'lead-report-' . now()->format('Y-m-d') . ($format === 'xlsx' ? '.xls' : '.csv');

        return $format === 'xlsx'
            ? ReportExporter::excel($filename, $headers, $data)
            : ReportExporter::csv($filename, $headers, $data);
    }

    public function revenueReport(Request $request): JsonResponse
    {
        $request->validate([
            'assigned_to'  => ['nullable', 'integer'],
            'deal_status'  => ['nullable', 'in:open,won,lost'],
            'payment_mode' => ['nullable', 'in:cash,cheque,bank_transfer,upi,card,other'],
            'date_from'    => ['nullable', 'date'],
            'date_to'      => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page'     => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'         => ['nullable', 'integer', 'min:1'],
        ]);

        $orgId = $request->user()->organization_id;
        $base  = $this->revenueReportQuery($orgId, $request, $this->resolveReportAssignedTo($request));

        $summary = $this->revenueReportSummary(clone $base);

        $payments = (clone $base)
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => [
                'summary' => $summary,
                'rows'    => RevenueReportResource::collection($payments->items())->resolve(),
            ],
            'meta' => [
                'total'        => $payments->total(),
                'per_page'     => $payments->perPage(),
                'current_page' => $payments->currentPage(),
                'last_page'    => $payments->lastPage(),
            ],
        ]);
    }

    public function exportRevenueReport(Request $request): StreamedResponse
    {
        $request->validate([
            'format'       => ['nullable', 'in:csv,xlsx'],
            'assigned_to'  => ['nullable', 'integer'],
            'deal_status'  => ['nullable', 'in:open,won,lost'],
            'payment_mode' => ['nullable', 'in:cash,cheque,bank_transfer,upi,card,other'],
            'date_from'    => ['nullable', 'date'],
            'date_to'      => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $orgId  = $request->user()->organization_id;
        $format = $request->input('format', 'csv');
        $rows   = $this->revenueReportQuery($orgId, $request, $this->resolveReportAssignedTo($request))
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();

        $headers = [
            'Deal Name', 'Client Name', 'Assigned Member', 'Deal Value',
            'Amount Received', 'Remaining Amount', 'Transaction Amount',
            'Payment Date', 'Payment Method', 'Transaction Notes', 'Deal Status',
        ];

        $data = $rows->map(function (DealPayment $payment) {
            $dealValue      = $payment->deal?->value !== null ? (float) $payment->deal->value : null;
            $amountReceived = $payment->deal?->payments_sum_amount !== null ? (float) $payment->deal->payments_sum_amount : 0.0;
            $remaining      = $dealValue !== null ? max(0, $dealValue - $amountReceived) : null;

            return [
                $payment->deal?->title,
                $payment->deal?->lead?->full_name,
                $payment->deal?->assignedTo?->name,
                $dealValue,
                $amountReceived,
                $remaining,
                (float) $payment->amount,
                $payment->payment_date?->format('Y-m-d'),
                $payment->payment_mode,
                $payment->notes,
                $payment->deal?->status,
            ];
        });

        $filename = 'revenue-report-' . now()->format('Y-m-d') . ($format === 'xlsx' ? '.xls' : '.csv');

        return $format === 'xlsx'
            ? ReportExporter::excel($filename, $headers, $data)
            : ReportExporter::csv($filename, $headers, $data);
    }

    private function leadReportQuery(int $orgId, Request $request, ?int $assignedTo = null): Builder
    {
        $followUpSub = Activity::query()
            ->select('due_at')
            ->whereColumn('activities.subject_id', 'leads.id')
            ->where(function ($q) {
                foreach (self::LEAD_SUBJECT_TYPES as $type) {
                    $q->orWhere('activities.subject_type', $type);
                }
            })
            ->whereIn('activities.type', ['task', 'meeting'])
            ->whereNotNull('activities.due_at')
            ->whereNull('activities.deleted_at')
            ->orderByDesc('activities.created_at')
            ->limit(1);

        $query = Lead::query()
            ->where('organization_id', $orgId)
            ->with(['assignedTo:id,name'])
            ->select('leads.*')
            ->selectSub($followUpSub, 'follow_up_date');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('company', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($assignedTo) {
            $query->where('assigned_to', $assignedTo);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
        }

        if ($dateTo = $request->input('date_to')) {
            $query->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        return $query;
    }

    /** @return array<string, int> */
    private function leadReportSummary(Builder $query): array
    {
        $counts = (clone $query)
            ->select([
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_leads"),
                DB::raw("SUM(CASE WHEN status = 'contacted' THEN 1 ELSE 0 END) as contacted"),
                DB::raw("SUM(CASE WHEN status = 'ringing' THEN 1 ELSE 0 END) as ringing"),
                DB::raw("SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as won"),
                DB::raw("SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost"),
            ])
            ->reorder()
            ->first();

        $proposalSent = (clone $query)
            ->whereHas('proposals', fn ($q) => $q->where('status', 'sent'))
            ->count();

        return [
            'total'          => (int) ($counts->total ?? 0),
            'new'            => (int) ($counts->new_leads ?? 0),
            'contacted'      => (int) ($counts->contacted ?? 0),
            'qualified'      => (int) ($counts->ringing ?? 0), // legacy key (now Ringing)
            'ringing'        => (int) ($counts->ringing ?? 0),
            'proposal_sent'  => $proposalSent,
            'won'            => (int) ($counts->won ?? 0),
            'lost'           => (int) ($counts->lost ?? 0),
        ];
    }

    private function revenueReportQuery(int $orgId, Request $request, ?int $assignedTo = null): Builder
    {
        $query = DealPayment::query()
            ->where('deal_payments.organization_id', $orgId)
            ->with([
                'deal' => fn ($q) => $q
                    ->select('id', 'title', 'value', 'status', 'lead_id', 'assigned_to')
                    ->withSum('payments', 'amount')
                    ->with([
                        'lead:id,first_name,last_name',
                        'assignedTo:id,name',
                    ]),
            ])
            ->join('deals', 'deals.id', '=', 'deal_payments.deal_id')
            ->whereNull('deals.deleted_at')
            ->select('deal_payments.*');

        if ($assignedTo) {
            $query->where('deals.assigned_to', $assignedTo);
        }

        if ($dealStatus = $request->input('deal_status')) {
            $query->where('deals.status', $dealStatus);
        }

        if ($paymentMode = $request->input('payment_mode')) {
            $query->where('deal_payments.payment_mode', $paymentMode);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->where('deal_payments.payment_date', '>=', Carbon::parse($dateFrom)->toDateString());
        }

        if ($dateTo = $request->input('date_to')) {
            $query->where('deal_payments.payment_date', '<=', Carbon::parse($dateTo)->toDateString());
        }

        return $query;
    }

    /** @return array<string, float|int> */
    private function revenueReportSummary(Builder $query): array
    {
        $stats = (clone $query)
            ->select([
                DB::raw('COALESCE(SUM(deal_payments.amount), 0) as total_revenue'),
                DB::raw('COUNT(*) as total_transactions'),
                DB::raw('COUNT(DISTINCT deal_payments.deal_id) as deal_count'),
            ])
            ->reorder()
            ->first();

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd   = now()->endOfMonth()->toDateString();

        $monthRevenue = (clone $query)
            ->whereBetween('deal_payments.payment_date', [$monthStart, $monthEnd])
            ->sum('deal_payments.amount');

        $totalRevenue = (float) ($stats->total_revenue ?? 0);
        $dealCount    = (int) ($stats->deal_count ?? 0);

        return [
            'total_revenue'            => $totalRevenue,
            'revenue_this_month'       => (float) $monthRevenue,
            'total_transactions'       => (int) ($stats->total_transactions ?? 0),
            'average_revenue_per_deal' => $dealCount > 0 ? round($totalRevenue / $dealCount, 2) : 0.0,
        ];
    }

    /**
     * Employees always see only their assigned leads/deals.
     * Owners and admins may filter by team member or view all.
     */
    private function resolveReportAssignedTo(Request $request): ?int
    {
        $user = $request->user();

        if ($user->isEmployee()) {
            return $user->id;
        }

        $assignedTo = $request->input('assigned_to');

        return $assignedTo ? (int) $assignedTo : null;
    }
}
