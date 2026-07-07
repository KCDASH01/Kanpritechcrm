<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'type'               => $this->type,
            'description'        => $this->description,
            'gateway'            => $this->gateway,
            'gateway_order_id'   => $this->gateway_order_id,
            'gateway_payment_id' => $this->gateway_payment_id,
            'quantity'           => $this->quantity,
            'amount'             => (float) $this->amount,
            'currency'           => $this->currency,
            'status'             => $this->status,
            'valid_until'        => $this->valid_until?->toDateString(),
            'created_at'         => $this->created_at?->toIso8601String(),

            // Loaded for receipt view
            'user' => $this->whenLoaded('user', fn () => [
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ]),
            'organization' => $this->whenLoaded('organization', fn () => [
                'name'  => $this->organization->name,
                'email' => $this->organization->email,
            ]),
        ];
    }
}
