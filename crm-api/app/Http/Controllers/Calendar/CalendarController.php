<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarEventResource;
use App\Models\Activity;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
