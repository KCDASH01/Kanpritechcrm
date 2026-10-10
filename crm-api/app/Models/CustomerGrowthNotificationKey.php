<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerGrowthNotificationKey extends Model
{
    protected $fillable = ['organization_id', 'deduplication_key', 'condition_hash', 'notified_at', 'resolved_at'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
