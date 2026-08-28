<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevenueReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $dealValue      = $this->deal?->value !== null ? (float) $this->deal->value : null;
        $amountReceived = $this->deal?->payments_sum_amount !== null ? (float) $this->deal->payments_sum_amount : 0.0;
        $remaining      = $dealValue !== null ? max(0, $dealValue - $amountReceived) : null;

        return [
            'id'                 => $this->id,
            'deal_name'          => $this->deal?->title,
            'client_name'        => $this->deal?->lead?->full_name,
            'assigned_member'    => $this->deal?->assignedTo?->name,
            'deal_value'         => $dealValue,
            'amount_received'    => $amountReceived,
            'remaining_amount'   => $remaining,
            'transaction_amount' => (float) $this->amount,
            'payment_date'       => $this->payment_date?->toDateString(),
            'payment_method'     => $this->payment_mode,
            'transaction_notes'  => $this->notes,
            'deal_status'        => $this->deal?->status,
        ];
    }
}
