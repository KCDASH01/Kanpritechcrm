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
            'client_id'        => $this->client_id,
            'client_type'      => $this->client_type,
            'business_type'    => $this->business_type,
            'market_type'      => $this->market_type,
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
            'state'            => $this->state,
            'country'          => $this->country,
            'notes'            => $this->notes,
            'score'            => $this->score,
            'pipeline_id'      => $this->pipeline_id,
            'stage_id'         => $this->stage_id,
            'external_lead_id' => $this->external_lead_id,
            'custom_fields'    => $this->custom_fields,
            'expected_value'   => $this->expected_value !== null ? (float) $this->expected_value : null,
            'currency'         => $this->currency ?: 'INR',
            'recurring_frequency' => $this->recurring_frequency,
            'recurring_amount' => $this->recurring_amount !== null ? (float) $this->recurring_amount : null,
            'recurring_start_date' => $this->recurring_start_date?->toDateString(),
            'recurring_end_type' => $this->recurring_end_type,
            'recurring_end_date' => $this->recurring_end_date?->toDateString(),
            'next_billing_date' => $this->next_billing_date?->toDateString(),
            'billing_cycles'   => $this->billing_cycles,
            'contract_value'   => $this->contract_value !== null ? (float) $this->contract_value : null,
            'department_id'    => $this->department_id,
            'client'           => new ClientResource($this->whenLoaded('client')),
            'department'       => new DepartmentResource($this->whenLoaded('department')),
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
