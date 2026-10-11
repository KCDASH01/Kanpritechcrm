<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationConnectionAudit extends Model
{
    protected $fillable = ['organization_id', 'connection_id', 'actor_id', 'action', 'status', 'details'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
