<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'content'      => $this->content,
            'is_pinned'    => $this->is_pinned,
            'notable_type' => $this->notable_type,
            'notable_id'   => $this->notable_id,
            'created_by'   => new UserResource($this->whenLoaded('createdBy')),
            'created_at'   => $this->created_at?->toIso8601String(),
            'updated_at'   => $this->updated_at?->toIso8601String(),
        ];
    }
}
