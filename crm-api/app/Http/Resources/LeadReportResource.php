<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'lead_name'       => $this->full_name,
            'company'         => $this->company,
            'contact_person'  => $this->full_name,
            'phone'           => $this->phone,
            'email'           => $this->email,
            'assigned_member' => $this->assignedTo?->name,
            'source'          => $this->source,
            'status'          => $this->status,
            'client_type'     => $this->client_type,
            'business_type'   => $this->business_type,
            'market_type'     => $this->market_type,
            'service_type'    => $this->types,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
            'follow_up_date'  => $this->follow_up_date
                ? (is_string($this->follow_up_date) ? $this->follow_up_date : $this->follow_up_date->toIso8601String())
                : null,
        ];
    }
}
