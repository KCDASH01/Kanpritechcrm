<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'user_id', 'plan', 'subscription_source',
        'start_date', 'end_date', 'is_active', 'status',
        'gateway', 'gateway_subscription_id', 'amount', 'currency',
        'external_metadata', 'extra_members_purchased',
    ];

    protected function casts(): array
    {
        return [
            'start_date'        => 'datetime',
            'end_date'          => 'datetime',
            'is_active'         => 'boolean',
            'amount'            => 'decimal:2',
            'external_metadata' => 'array',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function isBusiness(): bool
    {
        return $this->plan === 'business';
    }

    public function isEnterprise(): bool
    {
        return $this->plan === 'enterprise';
    }

    public function isPaid(): bool
    {
        return in_array($this->plan, ['business', 'enterprise']);
    }

    public function isExternal(): bool
    {
        return $this->subscription_source === 'external';
    }

    public function isExpired(): bool
    {
        return $this->end_date && $this->end_date->isPast();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->whereIn('status', ['active', 'trialing']);
    }
}
