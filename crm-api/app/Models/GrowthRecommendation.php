<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrowthRecommendation extends Model
{
    protected $fillable = ['organization_id', 'client_id', 'source_deal_id', 'source_recurring_business_id', 'assigned_to', 'existing_service', 'suggested_service', 'recommendation_type', 'reason', 'potential_value', 'currency', 'estimate_source', 'priority', 'suggested_follow_up_date', 'status', 'snoozed_until', 'dismissal_reason', 'converted_deal_id', 'fingerprint', 'metadata'];

    protected function casts(): array
    {
        return ['potential_value' => 'decimal:2', 'suggested_follow_up_date' => 'date', 'snoozed_until' => 'datetime', 'metadata' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function convertedDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'converted_deal_id');
    }
}
