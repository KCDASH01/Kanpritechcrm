<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company' => $this->company,
            'email' => $this->email,
            'phone' => $this->phone,
            'job_title' => $this->job_title,
            'website' => $this->website,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'assigned_to' => new UserResource($this->whenLoaded('assignedTo')),
            'leads_count' => $this->whenCounted('leads'),
            'deals_count' => $this->whenCounted('deals'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
