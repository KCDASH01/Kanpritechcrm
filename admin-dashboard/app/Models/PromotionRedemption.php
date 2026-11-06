<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionRedemption extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'applied_at' => 'datetime', 'reserved_at' => 'datetime'];
    }
}
