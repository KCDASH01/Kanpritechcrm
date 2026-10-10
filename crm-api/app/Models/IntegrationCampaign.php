<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationCampaign extends Model
{
    protected $fillable = [
        'organization_id', 'connection_id', 'assigned_to', 'platform', 'external_id',
        'name', 'campaign_type', 'status', 'start_date', 'end_date', 'target_market',
        'service', 'metadata',
    ];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'metadata' => 'array'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(IntegrationCampaignRecipient::class, 'campaign_id');
    }
}
