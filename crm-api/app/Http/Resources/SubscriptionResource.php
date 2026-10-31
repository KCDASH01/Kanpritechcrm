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
            'business'   => $this->billing_cycle ? 10 : 3,
            'growth'     => 3,
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
            'provider_status'          => $this->provider_status,
            'is_active'                => $this->is_active,
            'is_read_only'             => $this->isReadOnly(),
            'billing_cycle'            => $this->billing_cycle,
            'start_date'               => $this->start_date?->toDateString(),
            'end_date'                 => $this->end_date?->toDateString(),
            'is_expired'               => $this->isExpired(),
            'gateway'                  => $this->gateway,
            'amount'                   => $this->amount,
            'currency'                 => $this->currency,
            'authorized_at'            => $this->authorized_at?->toIso8601String(),
            'trial_started_at'         => $this->trial_started_at?->toIso8601String(),
            'trial_ends_at'            => $this->trial_ends_at?->toIso8601String(),
            'current_period_end'       => $this->current_period_end?->toIso8601String(),
            'next_charge_at'           => $this->next_charge_at?->toIso8601String(),
            'grace_ends_at'            => $this->grace_ends_at?->toIso8601String(),
            'cancel_requested_at'      => $this->cancel_requested_at?->toIso8601String(),
            'cancel_at_period_end'     => (bool) $this->cancel_at_period_end,
            'access_until'             => $this->access_until?->toIso8601String(),
            // Seat billing fields
            'extra_members_purchased'  => (int) ($this->extra_members_purchased ?? 0),
            'total_team_limit'         => $this->computeTotalTeamLimit(),
            'extra_member_price'       => 299,
        ];
    }
}
