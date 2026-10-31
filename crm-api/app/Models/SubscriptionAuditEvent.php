<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'subscription_id', 'actor_user_id', 'event_type',
        'before', 'after', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'metadata' => 'array', 'created_at' => 'datetime'];
    }
}
