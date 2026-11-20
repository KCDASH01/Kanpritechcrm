<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Carbon;

class AssistantAlertService
{
    private const LEAD_SUBJECT_TYPES = ['lead', Lead::class];

    /** @return array<int, array<string, mixed>> */
    public function importantAlerts(User $viewer): array
    {
        $timezone = $viewer->organization?->timezone ?: config('app.timezone');
        $now = Carbon::now($timezone);
        $soon = $now->copy()->addMinutes(30);

        $activities = Activity::query()
            ->where('activities.organization_id', $viewer->organization_id)
            ->whereIn('activities.subject_type', self::LEAD_SUBJECT_TYPES)
            ->where('activities.is_done', false)
            ->whereExists(function ($query) use ($viewer) {
                $query->selectRaw('1')->from('leads')
                    ->whereColumn('leads.id', 'activities.subject_id')
                    ->where('leads.organization_id', $viewer->organization_id)
                    ->whereNull('leads.deleted_at')
                    ->when(! $viewer->isAdmin() && ! $viewer->isOwner(), fn ($lead) => $lead->where('leads.assigned_to', $viewer->id));
            })
            ->where(function ($query) use ($soon) {
                $query->where('priority', 'high')
                    ->orWhere(function ($scheduled) use ($soon) {
                        $scheduled->whereIn('type', ['task', 'meeting'])->where('due_at', '<=', $soon);
                    });
            })
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->limit(100)
            ->get();
        $leads = Lead::query()->where('organization_id', $viewer->organization_id)
            ->whereIn('id', $activities->pluck('subject_id'))->get(['id', 'first_name', 'last_name', 'company'])->keyBy('id');
        $activities = $activities->unique(fn (Activity $activity) => $activity->type.':'.$activity->subject_id)
            ->map(fn (Activity $activity) => $this->toAlert($activity, $leads->get($activity->subject_id), $now))
            ->sortBy([['priority_rank', 'asc'], ['due_at', 'asc']])
            ->take(10)
            ->values();

        return $activities->map(function (array $alert) {
            unset($alert['priority_rank']);

            return $alert;
        })->all();
    }

    /** @return array<string, mixed> */
    private function toAlert(Activity $activity, ?Lead $subject, Carbon $now): array
    {
        $due = $activity->due_at?->copy()->setTimezone($now->timezone);
        $overdue = $due?->lt($now) ?? false;
        $hoursOverdue = $overdue ? $due->diffInHours($now) : 0;
        $isMeeting = $activity->type === 'meeting';
        $isFollowUp = $activity->type === 'task';
        $critical = $overdue && ($hoursOverdue >= 24 || $activity->priority === 'high');
        $severity = $critical ? 'critical' : ($overdue ? 'urgent' : 'important');
        $type = $isMeeting
            ? ($overdue ? 'meeting_attention' : 'upcoming_meeting')
            : ($isFollowUp ? ($overdue ? 'overdue_followup' : ($activity->priority === 'high' ? 'urgent_activity' : 'followup_due')) : 'urgent_activity');

        return [
            'id' => 'activity-'.$activity->id,
            'type' => $type,
            'severity' => $severity,
            'title' => $activity->title,
            'record_id' => (int) $activity->id,
            'lead_id' => (int) $activity->subject_id,
            'lead_name' => $subject?->full_name ?: 'Lead',
            'company' => $subject?->company,
            'due_at' => $activity->due_at?->toIso8601String(),
            'navigation' => $isMeeting ? '/meetings' : ($isFollowUp ? '/follow-ups' : '/activities'),
            'priority_rank' => match ($severity) {
                'critical' => 1,
                'urgent' => 2,
                default => 3,
            },
        ];
    }
}
