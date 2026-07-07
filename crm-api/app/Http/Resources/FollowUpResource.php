<?php

namespace App\Http\Resources;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Lead|null $lead */
        $lead = $this->subject instanceof Lead ? $this->subject : null;

        if (! $lead && in_array($this->subject_type, ['lead', Lead::class], true) && $this->subject_id) {
            $lead = Lead::find($this->subject_id);
        }

        $status = 'pending';
        if ($this->is_done) {
            $status = 'done';
        } elseif ($this->due_at && $this->due_at->lt(now())) {
            $status = 'overdue';
        }

        return [
            'id'          => $this->id,
            'activity_id' => $this->id,
            'lead_id'     => $lead?->id,
            'lead_name'   => $lead?->full_name ?? '—',
            'company'     => $lead?->company,
            'email'       => $lead?->email,
            'phone'       => $lead?->phone,
            'title'       => $this->title,
            'due_at'      => $this->due_at?->toIso8601String(),
            'completed_at'=> $this->completed_at?->toIso8601String(),
            'is_done'     => $this->is_done,
            'status'      => $status,
            'assigned_to' => new UserResource($this->whenLoaded('assignedTo')),
        ];
    }
}
