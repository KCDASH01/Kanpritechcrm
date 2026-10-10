<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IntegrationEvent extends Model
{
    protected $fillable = [
        'organization_id', 'connection_id', 'campaign_id', 'lead_id', 'assigned_to',
        'provider', 'external_event_id', 'event_type', 'status', 'classification',
        'payload', 'extracted_data', 'assignment_reason', 'error_message', 'attempts',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'extracted_data' => 'array', 'processed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(IntegrationCampaign::class, 'campaign_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function reviewItem(): HasOne
    {
        return $this->hasOne(IntegrationReviewItem::class);
    }
}
