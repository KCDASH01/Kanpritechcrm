<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'is_default'  => $this->is_default,
            'sort_order'  => $this->sort_order,
            'stages'      => StageResource::collection($this->whenLoaded('stages')),
        ];
    }
}
