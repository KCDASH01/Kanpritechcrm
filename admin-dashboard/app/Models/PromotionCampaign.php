<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionCampaign extends Model
{
    protected $fillable = [
        'name', 'code', 'kind', 'discount_type', 'percentage_basis_points',
        'fixed_amount_minor', 'maximum_discount_minor', 'minimum_eligible_minor',
        'eligible_currencies', 'audience', 'duration', 'discount_cycles', 'stackable',
        'priority', 'total_redemption_limit', 'per_customer_limit', 'starts_at',
        'ends_at', 'state', 'rules', 'created_by_admin_id', 'updated_by_admin_id', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'eligible_currencies' => 'array', 'rules' => 'array', 'stackable' => 'boolean',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'archived_at' => 'datetime',
        ];
    }

    public function planPrices(): BelongsToMany
    {
        return $this->belongsToMany(SubscriptionPlanPrice::class, 'promotion_campaign_plan_prices')
            ->withPivot(['gateway_offer_id', 'supported_payment_methods'])->withTimestamps();
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }
}
