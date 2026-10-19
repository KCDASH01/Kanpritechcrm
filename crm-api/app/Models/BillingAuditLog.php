<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingAuditLog extends Model
{
    protected $fillable = [
        'organization_id', 'actor_id', 'event', 'auditable_type', 'auditable_id',
        'before_values', 'after_values', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array'];
    }
}
