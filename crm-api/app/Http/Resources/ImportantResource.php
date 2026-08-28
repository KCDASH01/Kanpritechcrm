<?php

namespace App\Http\Resources;

use App\Models\Activity;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Lead $lead */
        $lead = $this->resource;

        /** @var Activity|null $activity */
        $activity = $lead->relationLoaded('importantActivity')
            ? $lead->getRelation('importantActivity')
            : null;

        $status = ($activity?->is_done ?? false) ? 'done' : 'pending';

        return [
            'id'           => $lead->id,
            'activity_id'  => $activity?->id ?? 0,
            'lead_id'      => $lead->id,
            'lead_name'    => $lead->full_name,
            'company'      => $lead->company,
            'email'        => $lead->email,
            'phone'        => $lead->phone,
            'types'        => $lead->types,
            'title'        => $activity?->title ?? 'Important',
            'remark'       => $activity?->description,
            'marked_at'    => $activity?->created_at?->toIso8601String() ?? $lead->updated_at?->toIso8601String(),
            'lead_date'    => $lead->lead_date?->toDateString(),
            'is_done'      => (bool) ($activity?->is_done ?? false),
            'status'       => $status,
            'assigned_to'  => new UserResource($this->whenLoaded('assignedTo')),
        ];
    }
}
