<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'avatar'       => $this->avatar,
            'role'         => $this->role,
            'is_sso_user'  => $this->is_sso_user,
            'sso_provider' => $this->sso_provider,
            'is_active'    => $this->is_active,
            'has_password' => !is_null($this->password),
            'organization' => new OrganizationResource($this->whenLoaded('organization')),
            'subscription' => new SubscriptionResource(
                $this->when(
                    $this->relationLoaded('organization') && $this->organization,
                    fn () => $this->organization->activeSubscription()
                )
            ),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
