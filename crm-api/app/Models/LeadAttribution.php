<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadAttribution extends Model
{
    protected $fillable = [
        'organization_id', 'lead_id', 'integration_event_id', 'campaign_id', 'platform',
        'source_account_id', 'external_lead_id', 'external_message_id',
        'campaign_external_id', 'adset_external_id', 'ad_external_id',
        'form_external_id', 'is_original', 'captured_at', 'metadata',
    ];

    protected function casts(): array
    {
        return ['is_original' => 'boolean', 'captured_at' => 'datetime', 'metadata' => 'array'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(IntegrationEvent::class, 'integration_event_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(IntegrationCampaign::class, 'campaign_id');
    }
}
