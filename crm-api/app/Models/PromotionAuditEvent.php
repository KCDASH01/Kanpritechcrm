<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'promotion_campaign_id', 'organization_id', 'actor_admin_id',
        'actor_user_id', 'event_type', 'before', 'after', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'metadata' => 'array', 'created_at' => 'datetime'];
    }
}
