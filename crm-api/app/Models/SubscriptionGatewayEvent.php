<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionGatewayEvent extends Model
{
    protected $fillable = [
        'gateway', 'event_id', 'event_type', 'organization_id', 'subscription_id',
        'gateway_subscription_id', 'payload_hash', 'payload', 'processing_status',
        'processing_error', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
