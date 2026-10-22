<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadTimeline;
use App\Models\SalesTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadConversionRatioService
{
    /**
     * Resolve one reporting window in the organization's business timezone.
     * End dates are inclusive for callers and represented internally as an exclusive bound.
     *
     * @param  array<string, mixed>  $filters
     * @return array{mode:string,start:?Carbon,end_exclusive:?Carbon,start_date:?string,end_date:?string,label:string,target_id:?int}
     */
    public function resolvePeriod(User $viewer, array $filters): array
    {
        $timezone = $viewer->organization?->timezone ?: config('app.timezone');
        $mode = (string) ($filters['conversion_period'] ?? 'current_month');
        $now = Carbon::now($timezone);
        $targetId = null;

        if ($mode === 'all_time') {
            return [
                'mode' => $mode, 'start' => null, 'end_exclusive' => null,
                'start_date' => null, 'end_date' => null, 'label' => 'All time', 'target_id' => null,
            ];
        }

        if ($mode === 'target') {
            if (empty($filters['conversion_target_id'])) {
                throw ValidationException::withMessages(['conversion_target_id' => 'Select a configured target period.']);
            }
            $target = SalesTarget::query()
                ->where('organization_id', $viewer->organization_id)
                ->when($viewer->isEmployee(), fn ($query) => $query->where('user_id', $viewer->id))
                ->find((int) $filters['conversion_target_id']);
            if (! $target) {
                throw ValidationException::withMessages(['conversion_target_id' => 'The selected target period is not available.']);
            }
            $start = Carbon::parse($target->period_start, $timezone)->startOfDay();
            $end = Carbon::parse($target->period_end ?: $start->copy()->endOfMonth(), $timezone)->endOfDay();
            $targetId = (int) $target->id;
            $label = $start->format('d M Y').' – '.$end->format('d M Y').' · Target period';
        } elseif ($mode === 'custom') {
            if (empty($filters['conversion_start']) || empty($filters['conversion_end'])) {
                throw ValidationException::withMessages(['conversion_start' => 'Choose both start and end dates.']);
            }
            $start = Carbon::parse((string) $filters['conversion_start'], $timezone)->startOfDay();
            $end = Carbon::parse((string) $filters['conversion_end'], $timezone)->endOfDay();
            if ($end->lt($start)) {
                throw ValidationException::withMessages(['conversion_end' => 'End date must be on or after the start date.']);
            }
            $label = $start->format('d M Y').' – '.$end->format('d M Y');
        } else {
            $month = $mode === 'month' && ! empty($filters['conversion_month'])
                ? Carbon::createFromFormat('Y-m', (string) $filters['conversion_month'], $timezone)
                : $now;
            $start = $month->copy()->startOfMonth()->startOfDay();
            $end = $month->copy()->endOfMonth()->endOfDay();
            $label = $start->format('F Y');
            $mode = $mode === 'month' ? 'month' : 'current_month';
        }

        $storageTimezone = config('app.timezone');

        return [
            'mode' => $mode,
            'start' => $start->copy()->setTimezone($storageTimezone),
            'end_exclusive' => $end->copy()->addSecond()->setTimezone($storageTimezone),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'label' => $label,
            'target_id' => $targetId,
        ];
    }

    /** @return array<int, array{id:int,label:string,start_date:string,end_date:string}> */
    public function targetPeriods(User $viewer): array
    {
        return SalesTarget::query()
            ->where('organization_id', $viewer->organization_id)
            ->when($viewer->isEmployee(), fn ($query) => $query->where('user_id', $viewer->id))
            ->orderByDesc('period_start')->orderByDesc('id')->get(['id', 'period_start', 'period_end'])
            ->unique(fn (SalesTarget $target) => $target->period_start?->toDateString().'|'.($target->period_end?->toDateString() ?? ''))
            ->map(function (SalesTarget $target) {
                $start = Carbon::parse($target->period_start);
                $end = Carbon::parse($target->period_end ?: $start->copy()->endOfMonth());

                return [
                    'id' => (int) $target->id,
                    'label' => $start->format('d M Y').' – '.$end->format('d M Y'),
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                ];
            })->values()->all();
    }

    /**
     * @param  Collection<int, User>  $members
     * @param  array{mode:string,start:?Carbon,end_exclusive:?Carbon,start_date:?string,end_date:?string,label:string,target_id:?int}  $period
     * @return array{team:array<string,mixed>,members:array<int,array<string,mixed>>}
     */
    public function calculate(int $organizationId, Collection $members, array $period): array
    {
        $memberIds = $members->pluck('id')->map(fn ($id) => (int) $id)->values();
        $emptyMembers = $memberIds->mapWithKeys(fn (int $id) => [$id => $this->result(0, 0, $period)])->all();
        if ($memberIds->isEmpty()) {
            return ['team' => $this->result(0, 0, $period), 'members' => $emptyMembers];
        }

        $leads = Lead::query()->where('organization_id', $organizationId)
            ->when($period['start'] && $period['end_exclusive'], function ($query) use ($period) {
                $query->where(function ($candidate) use ($period) {
                    $candidate->where(function ($created) use ($period) {
                        $created->where('leads.created_at', '>=', $period['start'])
                            ->where('leads.created_at', '<', $period['end_exclusive']);
                    })->orWhereExists(function ($timeline) use ($period) {
                        $timeline->selectRaw('1')->from('lead_timeline')
                            ->whereColumn('lead_timeline.lead_id', 'leads.id')
                            ->whereIn('lead_timeline.action', ['assigned', 'deal_created'])
                            ->where('lead_timeline.created_at', '>=', $period['start'])
                            ->where('lead_timeline.created_at', '<', $period['end_exclusive']);
                    })->orWhereExists(function ($deals) use ($period) {
                        $deals->selectRaw('1')->from('deals')
                            ->whereColumn('deals.lead_id', 'leads.id')
                            ->where('deals.created_at', '>=', $period['start'])
                            ->where('deals.created_at', '<', $period['end_exclusive']);
                    });
                });
            })->get(['id', 'assigned_to', 'created_at']);
        if ($leads->isEmpty()) {
            return ['team' => $this->result(0, 0, $period), 'members' => $emptyMembers];
        }

        $leadIds = $leads->pluck('id');
        $timeline = LeadTimeline::query()->where('organization_id', $organizationId)
            ->whereIn('lead_id', $leadIds)
            ->whereIn('action', ['assigned', 'deal_created'])
            ->orderBy('created_at')->orderBy('id')->get(['id', 'lead_id', 'action', 'meta', 'created_at'])
            ->groupBy('lead_id');
        $legacyConversions = DB::table('deals')->where('organization_id', $organizationId)
            ->whereIn('lead_id', $leadIds)->whereNotNull('lead_id')
            ->selectRaw('lead_id, MIN(created_at) as converted_at')->groupBy('lead_id')
            ->pluck('converted_at', 'lead_id');

        $assigned = $memberIds->mapWithKeys(fn (int $id) => [$id => []])->all();
        $converted = $memberIds->mapWithKeys(fn (int $id) => [$id => []])->all();
        $teamAssigned = [];
        $teamConverted = [];
        $allowed = array_fill_keys($memberIds->all(), true);

        foreach ($leads as $lead) {
            $events = $timeline->get($lead->id, collect());
            $assignmentEvents = $events->where('action', 'assigned')->values();
            $firstAssignment = $assignmentEvents->first();
            $initialAssignee = $firstAssignment
                ? $this->assignmentId($firstAssignment->meta, 'from')
                : ($lead->assigned_to ? (int) $lead->assigned_to : null);
            $segments = [['assignee' => $initialAssignee, 'started_at' => $lead->created_at]];
            foreach ($assignmentEvents as $event) {
                $segments[] = ['assignee' => $this->assignmentId($event->meta, 'to'), 'started_at' => $event->created_at];
            }

            foreach ($segments as $segment) {
                $assignee = $segment['assignee'];
                if ($assignee && isset($allowed[$assignee]) && $this->inside($segment['started_at'], $period)) {
                    $assigned[$assignee][$lead->id] = true;
                    $teamAssigned[$lead->id] = true;
                }
            }

            $conversionEvent = $events->firstWhere('action', 'deal_created');
            $convertedAt = $conversionEvent?->created_at
                ?? (($legacyConversions[$lead->id] ?? null) ? Carbon::parse($legacyConversions[$lead->id]) : null);
            if (! $convertedAt || ! $this->inside($convertedAt, $period)) {
                continue;
            }

            $ownerAtConversion = $initialAssignee;
            foreach ($assignmentEvents as $event) {
                if ($event->created_at->lte($convertedAt)) {
                    $ownerAtConversion = $this->assignmentId($event->meta, 'to');
                }
            }
            if ($ownerAtConversion && isset($assigned[$ownerAtConversion][$lead->id])) {
                $converted[$ownerAtConversion][$lead->id] = true;
                $teamConverted[$lead->id] = true;
            }
        }

        $memberResults = [];
        foreach ($memberIds as $memberId) {
            $memberResults[$memberId] = $this->result(count($assigned[$memberId]), count($converted[$memberId]), $period);
        }

        return [
            'team' => $this->result(count($teamAssigned), count($teamConverted), $period),
            'members' => $memberResults,
        ];
    }

    /** @param array<string, mixed>|null $meta */
    private function assignmentId(?array $meta, string $side): ?int
    {
        $value = $meta['assigned_to'][$side] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @param array{start:?Carbon,end_exclusive:?Carbon} $period */
    private function inside(mixed $timestamp, array $period): bool
    {
        if (! $timestamp) {
            return false;
        }
        if ($period['start'] === null || $period['end_exclusive'] === null) {
            return true;
        }
        $at = $timestamp instanceof Carbon ? $timestamp : Carbon::parse($timestamp);

        return $at->gte($period['start']) && $at->lt($period['end_exclusive']);
    }

    /** @param array<string, mixed> $period */
    private function result(int $assigned, int $converted, array $period): array
    {
        return [
            'assigned_leads' => $assigned,
            'converted_leads' => $converted,
            'ratio' => $assigned > 0 ? round(($converted / $assigned) * 100, 2) : 0.0,
            'period' => [
                'mode' => $period['mode'], 'start_date' => $period['start_date'],
                'end_date' => $period['end_date'], 'label' => $period['label'], 'target_id' => $period['target_id'],
            ],
        ];
    }
}
