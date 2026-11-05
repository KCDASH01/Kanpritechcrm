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
        'billing_cycle', 'provider_status',
        'start_date', 'end_date', 'is_active', 'status',
        'gateway', 'gateway_subscription_id', 'amount', 'currency',
        'gateway_plan_id', 'gateway_customer_id',
        'authorized_at', 'trial_started_at', 'trial_ends_at',
        'current_period_start', 'current_period_end', 'next_charge_at',
        'grace_ends_at', 'cancel_requested_at', 'cancelled_at', 'access_until',
        'cancel_at_period_end', 'last_payment_failed_at',
        'external_metadata', 'extra_members_purchased',
    ];

    protected function casts(): array
    {
        return [
            'start_date'        => 'datetime',
            'end_date'          => 'datetime',
            'authorized_at'     => 'datetime',
            'trial_started_at'  => 'datetime',
            'trial_ends_at'     => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_charge_at'    => 'datetime',
            'grace_ends_at'     => 'datetime',
            'cancel_requested_at' => 'datetime',
            'cancelled_at'      => 'datetime',
            'access_until'      => 'datetime',
            'last_payment_failed_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
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

    public function isGrowth(): bool
    {
        return $this->plan === 'growth';
    }

    public function isEnterprise(): bool
    {
        return $this->plan === 'enterprise';
    }

    public function isPaid(): bool
    {
        return in_array($this->plan, ['growth', 'business', 'enterprise']);
    }

    public function isExternal(): bool
    {
        return $this->subscription_source === 'external';
    }

    public function isExpired(): bool
    {
        $boundary = $this->access_until ?? $this->end_date;

        return $boundary && $boundary->isPast();
    }

    public function isReadOnly(): bool
    {
        if (! $this->billing_cycle) {
            return false;
        }

        if (! $this->is_active || in_array($this->status, ['expired', 'cancelled'], true)) {
            return true;
        }

        return (bool) $this->access_until?->isPast()
            && ! (bool) $this->grace_ends_at?->isFuture();
    }

    public function authorizationState(): string
    {
        if (! $this->billing_cycle || $this->plan === 'free') {
            return 'not_applicable';
        }

        return $this->authorized_at
            && in_array($this->provider_status, ['authenticated', 'active'], true)
                ? 'authorized'
                : 'pending';
    }

    public function renewalMode(): string
    {
        if ($this->plan === 'free' || $this->cancel_requested_at || $this->isExpired()) {
            return 'none';
        }

        if ($this->authorizationState() === 'authorized' && $this->is_active) {
            return 'automatic';
        }

        return $this->billing_cycle ? 'pending' : 'manual';
    }

    public function lifecycleState(): string
    {
        if ($this->plan === 'free') {
            return 'free';
        }

        if ($this->status === 'expired' || $this->isExpired()) {
            return 'expired';
        }

        if ($this->grace_ends_at?->isFuture() && $this->last_payment_failed_at) {
            return 'grace_period';
        }

        if ($this->cancel_requested_at) {
            return 'cancellation_pending';
        }

        if ($this->status === 'cancelled') {
            return 'cancelled';
        }

        if ($this->status === 'trialing') {
            return $this->authorizationState() === 'authorized'
                ? 'trial_authorized'
                : 'trial_pending_authorization';
        }

        if ($this->status === 'active' && $this->renewalMode() === 'automatic') {
            return 'active_auto_renew';
        }

        return $this->status === 'active' ? 'active' : 'unknown';
    }

    public function canResumeAuthorization(): bool
    {
        return $this->lifecycleState() === 'trial_pending_authorization'
            && ! $this->is_active;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->whereIn('status', ['active', 'trialing']);
    }
}
