<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationAsset extends Model
{
    protected $fillable = [
        'organization_id', 'connection_id', 'provider', 'asset_type', 'external_id',
        'parent_external_id', 'name', 'status', 'is_selected', 'access_token',
        'capabilities', 'metadata', 'last_verified_at',
    ];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'is_selected' => 'boolean', 'access_token' => 'encrypted',
            'capabilities' => 'array', 'metadata' => 'array', 'last_verified_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }
}
