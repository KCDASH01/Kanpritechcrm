<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    private function computeTotalTeamLimit(): int
    {
        $base = match ($this->plan) {
            'enterprise' => 10,
            'business'   => 3,
            default      => 0,
        };
        return $base + (int) ($this->extra_members_purchased ?? 0);
    }

    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'plan'                     => $this->plan,
            'subscription_source'      => $this->subscription_source,
            'status'                   => $this->status,
            'is_active'                => $this->is_active,
            'start_date'               => $this->start_date?->toDateString(),
            'end_date'                 => $this->end_date?->toDateString(),
            'is_expired'               => $this->isExpired(),
            'gateway'                  => $this->gateway,
            'amount'                   => $this->amount,
            'currency'                 => $this->currency,
            // Seat billing fields
            'extra_members_purchased'  => (int) ($this->extra_members_purchased ?? 0),
            'total_team_limit'         => $this->computeTotalTeamLimit(),
            'extra_member_price'       => 299,
        ];
    }
}
