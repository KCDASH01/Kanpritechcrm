<?php

namespace App\Http\Resources;

use App\Models\Activity;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Lead $lead */
        $lead = $this->resource;

        /** @var Activity|null $activity */
        $activity = $lead->relationLoaded('followUpActivity')
            ? $lead->getRelation('followUpActivity')
            : null;

        $status = 'pending';
        if ($activity?->is_done) {
            $status = 'done';
        } elseif ($activity?->due_at && $activity->due_at->lt(now())) {
            $status = 'overdue';
        }

        return [
            'id'           => $lead->id,
            'activity_id'  => $activity?->id ?? 0,
            'lead_id'      => $lead->id,
            'lead_name'    => $lead->full_name,
            'company'      => $lead->company,
            'email'        => $lead->email,
            'phone'        => $lead->phone,
            'title'        => $activity?->title ?? 'Follow-up',
            'due_at'       => $activity?->due_at?->toIso8601String(),
            'completed_at' => $activity?->completed_at?->toIso8601String(),
            'is_done'      => (bool) ($activity?->is_done ?? false),
            'status'       => $status,
            'assigned_to'  => new UserResource($this->whenLoaded('assignedTo')),
        ];
    }
}
