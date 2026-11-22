<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadImportRow extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'issues' => 'array', 'warning' => 'boolean'];
    }
}
