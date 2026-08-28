<?php

namespace App\Http\Resources;

use App\Models\Activity;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Activity $activity */
        $activity = $this->resource;

        /** @var Lead|null $lead */
        $lead = $activity->relationLoaded('calendarLead')
            ? $activity->getRelation('calendarLead')
            : null;

        $status = 'pending';
        if ($activity->is_done) {
            $status = 'done';
        } elseif ($activity->due_at && $activity->due_at->lt(now())) {
            $status = 'overdue';
        }

        return [
            'id'           => $activity->id,
            'type'         => $activity->type,
            'title'        => $activity->title,
            'description'  => $activity->description,
            'due_at'       => $activity->due_at?->toIso8601String(),
            'completed_at' => $activity->completed_at?->toIso8601String(),
            'is_done'      => $activity->is_done,
            'priority'     => $activity->priority,
            'subject_type' => $activity->subject_type,
            'subject_id'   => $activity->subject_id,
            'lead_id'      => $lead?->id ?? $activity->subject_id,
            'lead_name'    => $lead?->full_name ?? '',
            'lead_status'  => $lead?->status,
            'status'       => $status,
            'assigned_to'  => new UserResource($this->whenLoaded('assignedTo')),
            'created_by'   => new UserResource($this->whenLoaded('createdBy')),
            'created_at'   => $activity->created_at?->toIso8601String(),
        ];
    }
}
