<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationAutomation extends Model
{
    protected $fillable = ['organization_id', 'created_by', 'name', 'template_key', 'trigger', 'conditions', 'actions', 'is_active'];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'actions' => 'array', 'is_active' => 'boolean'];
    }
}
