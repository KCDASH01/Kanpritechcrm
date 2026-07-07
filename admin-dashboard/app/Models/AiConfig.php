<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiConfig extends Model
{
    protected $fillable = [
        'label',
        'provider',
        'api_key',
        'model',
        'max_tokens',
        'temperature',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active'   => 'boolean',
            'temperature' => 'float',
            'max_tokens'  => 'integer',
        ];
    }
}
