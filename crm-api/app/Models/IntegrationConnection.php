<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IntegrationConnection extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'connected_by', 'provider', 'name', 'status',
        'external_account_id', 'account_email', 'access_token', 'refresh_token',
        'token_expires_at', 'scopes', 'settings', 'last_synced_at', 'last_error',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'settings' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(IntegrationCampaign::class, 'connection_id');
    }
}
