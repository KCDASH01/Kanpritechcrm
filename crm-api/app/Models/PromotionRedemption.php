<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionRedemption extends Model
{
    protected $fillable = [
        'reservation_token', 'idempotency_key', 'promotion_campaign_id',
        'organization_id', 'subscription_id', 'original_amount_minor',
        'expected_discount_minor', 'actual_discount_minor', 'currency',
        'successful_cycles', 'status', 'gateway', 'gateway_offer_id',
        'gateway_payment_id', 'gateway_invoice_id', 'reserved_at', 'expires_at',
        'applied_at', 'released_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'original_amount_minor' => 'integer',
            'expected_discount_minor' => 'integer',
            'actual_discount_minor' => 'integer',
            'successful_cycles' => 'integer',
            'reserved_at' => 'datetime',
            'expires_at' => 'datetime',
            'applied_at' => 'datetime',
            'released_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PromotionCampaign::class, 'promotion_campaign_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
