<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'pipeline_id' => $this->pipeline_id,
            'name'        => $this->name,
            'color'       => $this->color,
            'sort_order'  => $this->sort_order,
            'probability' => $this->probability,
            'is_won'      => $this->is_won,
            'is_lost'     => $this->is_lost,
        ];
    }
}
