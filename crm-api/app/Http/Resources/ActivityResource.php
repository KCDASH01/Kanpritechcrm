<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'type'         => $this->type,
            'title'        => $this->title,
            'description'  => $this->description,
            'due_at'       => $this->due_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_done'      => $this->is_done,
            'priority'     => $this->priority,
            'subject_type' => $this->subject_type,
            'subject_id'   => $this->subject_id,
            'assigned_to'  => new UserResource($this->whenLoaded('assignedTo')),
            'created_by'   => new UserResource($this->whenLoaded('createdBy')),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
