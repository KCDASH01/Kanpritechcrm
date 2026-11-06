<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlanPrice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'is_active' => 'boolean'];
    }
}
