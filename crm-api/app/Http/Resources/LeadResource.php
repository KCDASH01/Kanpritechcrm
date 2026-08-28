<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'full_name'        => $this->full_name,
            'first_name'       => $this->first_name,
            'last_name'        => $this->last_name,
            'email'            => $this->email,
            'phone'            => $this->phone,
            'company'          => $this->company,
            'job_title'        => $this->job_title,
            'website'          => $this->website,
            'status'           => $this->status,
            'source'           => $this->source,
            'types'            => $this->types,
            'industry'         => $this->industry,
            'city'             => $this->city,
            'country'          => $this->country,
            'notes'            => $this->notes,
            'score'            => $this->score,
            'pipeline_id'      => $this->pipeline_id,
            'stage_id'         => $this->stage_id,
            'external_lead_id' => $this->external_lead_id,
            'custom_fields'    => $this->custom_fields,
            'assigned_to'      => new UserResource($this->whenLoaded('assignedTo')),
            'created_by'       => new UserResource($this->whenLoaded('createdBy')),
            'stage'            => new StageResource($this->whenLoaded('stage')),
            'pipeline'         => new PipelineResource($this->whenLoaded('pipeline')),
            'proposals_count'  => (int) ($this->proposals_count ?? 0),
            'lost_reason'      => $this->lost_reason,
            'lead_date'        => $this->lead_date?->toDateString(),
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
