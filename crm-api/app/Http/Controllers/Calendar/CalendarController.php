<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarEventResource;
use App\Models\Activity;
use App\Models\DealPayment;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarController extends Controller
{
    /** @var list<string> */
    private const LEAD_SUBJECT_TYPES = ['lead', Lead::class];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        $orgId     = $request->user()->organization_id;
        $user      = $request->user();
        $isManager = $user->isAdmin() || $user->isOwner();

        // Use app timezone day bounds (Asia/Kolkata) so range matches local calendar dates.
        $from = $request->input('date_from') . ' 00:00:00';
        $to   = $request->input('date_to') . ' 23:59:59';

        $subjectTypes = self::LEAD_SUBJECT_TYPES;

        $query = Activity::query()
            ->join('leads', 'leads.id', '=', 'activities.subject_id')
            ->where('activities.organization_id', $orgId)
            ->whereNull('activities.deleted_at')
            ->whereNotNull('activities.due_at')
            ->whereBetween('activities.due_at', [$from, $to])
            ->where(function ($q) use ($subjectTypes) {
                foreach ($subjectTypes as $type) {
                    $q->orWhere('activities.subject_type', $type);
                }
            })
            ->where('leads.organization_id', $orgId)
            ->whereNull('leads.deleted_at')
            ->where(function ($q) {
                // Follow-up leads → task activities; meeting leads → meeting activities.
                // Include both done and pending so completed items still appear (green on UI).
                $q->where(function ($q2) {
                    $q2->where('leads.status', 'followup')
                        ->where('activities.type', 'task');
                })->orWhere(function ($q2) {
                    $q2->where('leads.status', 'meeting')
                        ->where('activities.type', 'meeting');
                });
            });

        if (! $isManager) {
            $query->where('leads.assigned_to', $user->id);
        } elseif ($request->boolean('unassigned')) {
            $query->whereNull('leads.assigned_to');
        } elseif ($assignedTo = $request->input('assigned_to')) {
            $query->where('leads.assigned_to', $assignedTo);
        }

        $activities = $query
            ->select('activities.*')
            ->with(['assignedTo', 'createdBy'])
            ->orderBy('activities.due_at')
            ->get();

        $leads = Lead::whereIn('id', $activities->pluck('subject_id')->unique())
            ->get()
            ->keyBy('id');

        $activities->each(function (Activity $activity) use ($leads) {
            $activity->setRelation('calendarLead', $leads->get($activity->subject_id));
        });

        return response()->json([
            'data' => CalendarEventResource::collection($activities)->resolve(),
        ]);
    }

    /** Read-only collection calendar backed by existing payment transactions. */
    public function collections(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'assigned_to' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $assignedTo = $user->isEmployee() ? (int) $user->id : null;

        if (! $user->isEmployee() && isset($data['assigned_to'])) {
            $assignedTo = (int) User::query()
                ->where('organization_id', $orgId)
                ->where('is_active', true)
                ->where('role', '!=', 'owner')
                ->findOrFail((int) $data['assigned_to'])
                ->id;
        }

        $month = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        $from = $month->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();

        $payments = DealPayment::query()
            ->where('deal_payments.organization_id', $orgId)
            ->whereBetween('deal_payments.payment_date', [$from, $to])
            ->whereHas('deal', fn ($deal) => $deal
                ->where('organization_id', $orgId)
                ->when($assignedTo, fn ($query) => $query->where('assigned_to', $assignedTo)))
            ->with(['deal' => fn ($deal) => $deal
                ->select('id', 'organization_id', 'client_id', 'lead_id', 'assigned_to', 'title', 'currency', 'status')
                ->with(['client:id,first_name,last_name,company', 'lead:id,first_name,last_name', 'assignedTo:id,name'])])
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        $transactions = $payments->map(fn (DealPayment $payment) => [
            'id' => (int) $payment->id,
            'payment_date' => $payment->payment_date?->toDateString(),
            'client_name' => $payment->deal?->client?->company
                ?: ($payment->deal?->client?->full_name ?: $payment->deal?->lead?->full_name),
            'deal_id' => $payment->deal_id,
            'deal_reference' => $payment->deal?->title,
            'amount' => (float) $payment->amount,
            'currency' => $payment->deal?->currency ?: 'INR',
            'payment_method' => $payment->payment_mode,
            'responsible_employee' => $payment->deal?->assignedTo?->name,
            // deal_payments rows are the CRM's received-payment ledger; there is
            // no separate pending/refunded status column in the current schema.
            'payment_status' => 'received',
            'deal_status' => $payment->deal?->status,
        ])->values();

        $daily = $transactions->groupBy('payment_date')->map(function ($rows, string $date) {
            return [
                'date' => $date,
                'transaction_count' => $rows->count(),
                'totals' => $rows->groupBy('currency')->map(fn ($currencyRows, string $currency) => [
                    'currency' => $currency,
                    'amount' => round((float) $currencyRows->sum('amount'), 2),
                ])->values(),
            ];
        })->values();

        return response()->json(['data' => [
            'month' => $data['month'],
            'summary' => [
                'collection_days' => $daily->count(),
                'transaction_count' => $transactions->count(),
                'totals' => $transactions->groupBy('currency')->map(fn ($currencyRows, string $currency) => [
                    'currency' => $currency,
                    'amount' => round((float) $currencyRows->sum('amount'), 2),
                ])->values(),
            ],
            'daily' => $daily,
            'transactions' => $transactions,
        ]]);
    }
}
