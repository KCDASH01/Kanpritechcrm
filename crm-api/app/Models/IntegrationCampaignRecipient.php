<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationCampaignRecipient extends Model
{
    protected $fillable = [
        'organization_id', 'campaign_id', 'lead_id', 'email', 'message_id',
        'thread_id', 'status', 'unsubscribed_at', 'metadata',
    ];

    protected function casts(): array
    {
        return ['unsubscribed_at' => 'datetime', 'metadata' => 'array'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(IntegrationCampaign::class, 'campaign_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
