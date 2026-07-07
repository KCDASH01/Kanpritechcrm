<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'slug'     => $this->slug,
            'email'    => $this->email,
            'phone'    => $this->phone,
            'website'  => $this->website,
            'logo'     => $this->logo,
            'address'  => $this->address,
            'city'     => $this->city,
            'country'  => $this->country,
            'timezone' => $this->timezone,
            'is_active'=> $this->is_active,
        ];
    }
}
