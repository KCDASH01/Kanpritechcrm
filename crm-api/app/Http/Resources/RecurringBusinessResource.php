<?php

namespace App\Http\Resources;

use App\Services\RecurringRevenueService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecurringBusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $calculator = app(RecurringRevenueService::class);
        $collected = $calculator->collectedAmount($this->resource);
        $due = $calculator->dueAmount($this->resource, now());
        $upcoming = $calculator->upcomingBillingDate($this->resource, now());
        $dealDeleted = $calculator->dealWasDeleted($this->resource);
        $status = $dealDeleted && in_array($this->status, ['ACTIVE', 'PAUSED'], true)
            ? 'CANCELLED'
            : ($this->status === 'ACTIVE' && $this->end_date && $this->end_date->isPast() ? 'EXPIRED' : $this->status);
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'service_type' => $this->service_type,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'frequency' => $this->frequency,
            'start_date' => $this->start_date?->toDateString(),
            'next_billing_date' => $upcoming?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'billing_cycles' => $this->billing_cycles,
            'contract_value' => $this->contract_value !== null ? (float) $this->contract_value : null,
            'status' => $status,
            'notes' => $this->notes,
            'deal_deleted_at' => $dealDeleted ? $this->deal?->deleted_at?->toIso8601String() : null,
            'collected_revenue' => $collected,
            'due_revenue' => $due,
            'outstanding' => max(0, round($due - $collected, 2)),
            'client' => new ClientResource($this->whenLoaded('client')),
            'deal' => new DealResource($this->whenLoaded('deal')),
            'assigned_to' => new UserResource($this->whenLoaded('assignedTo')),
            'department' => new DepartmentResource($this->whenLoaded('department')),
        ];
    }
}
