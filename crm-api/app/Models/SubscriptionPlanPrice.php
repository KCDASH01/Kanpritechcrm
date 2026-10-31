<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlanPrice extends Model
{
    protected $fillable = [
        'plan', 'billing_cycle', 'currency', 'amount_minor', 'gateway',
        'gateway_plan_id', 'tax_exclusive', 'is_public', 'is_active',
        'effective_from', 'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer', 'tax_exclusive' => 'boolean',
            'is_public' => 'boolean', 'is_active' => 'boolean',
            'effective_from' => 'datetime', 'effective_until' => 'datetime',
        ];
    }
}
