<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    protected $fillable = [
        'name', 'slug', 'email', 'phone', 'website',
        'logo', 'address', 'city', 'country', 'timezone',
        'settings', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'settings'  => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->where('role', 'owner')->oldest();
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('is_active', true)
            ->whereIn('status', ['active', 'trialing'])
            ->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function pipelines(): HasMany
    {
        return $this->hasMany(Pipeline::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function activeSubscription(): ?Subscription
    {
        // Order by plan priority (enterprise > business > free) first, then by recency.
        // This ensures an internal CRM-paid Enterprise subscription always wins over
        // an external SSO-synced Business subscription, regardless of creation time.
        return $this->subscriptions()
            ->where('is_active', true)
            ->whereIn('status', ['active', 'trialing'])
            ->orderByRaw("FIELD(plan, 'enterprise', 'business', 'free')")
            ->orderByDesc('created_at')
            ->first();
    }

    public function isBusinessPlan(): bool
    {
        $sub = $this->activeSubscription();

        // An expired Business subscription is treated as Free immediately —
        // the nightly cron will formally downgrade it, but this ensures
        // correct behaviour in real-time even before the cron runs.
        if (! $sub || $sub->plan !== 'business') {
            return false;
        }

        return ! $sub->isExpired();
    }

    public function isEnterprisePlan(): bool
    {
        $sub = $this->activeSubscription();

        if (! $sub || $sub->plan !== 'enterprise') {
            return false;
        }

        return ! $sub->isExpired();
    }

    /**
     * True for Business OR Enterprise (any paid plan).
     */
    public function isPaidPlan(): bool
    {
        return $this->isBusinessPlan() || $this->isEnterprisePlan();
    }

    /**
     * Total allowed team members: plan free seats + extra purchased seats.
     * Returns 0 for free plan (no team management).
     */
    public function totalTeamMemberLimit(): int
    {
        $sub = $this->activeSubscription();

        if (! $sub || $sub->isExpired()) {
            return 0;
        }

        $base = match ($sub->plan) {
            'enterprise' => 10,
            'business'   => 3,
            default      => 0,
        };

        return $base + (int) ($sub->extra_members_purchased ?? 0);
    }

    public static function generateSlug(string $name): string
    {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $name));
        $slug = trim($base, '-');
        $i    = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
