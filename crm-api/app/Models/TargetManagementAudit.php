<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TargetManagementAudit extends Model
{
    protected $fillable = [
        'organization_id', 'entity_type', 'entity_id', 'action',
        'before_values', 'after_values', 'actor_id',
    ];

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array'];
    }
}
