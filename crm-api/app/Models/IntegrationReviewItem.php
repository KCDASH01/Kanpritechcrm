<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationReviewItem extends Model
{
    protected $fillable = [
        'organization_id', 'integration_event_id', 'assigned_to', 'reviewed_by',
        'linked_lead_id', 'reason', 'status', 'source_summary', 'extracted_data',
        'resolution_notes', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_summary' => 'array', 'extracted_data' => 'array', 'reviewed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(IntegrationEvent::class, 'integration_event_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function linkedLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'linked_lead_id');
    }
}
