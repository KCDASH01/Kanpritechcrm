<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionTransaction extends Model
{
    protected $fillable = [
        'organization_id', 'subscription_id', 'gateway', 'gateway_payment_id',
        'gateway_invoice_id', 'type', 'status', 'amount_minor', 'currency',
        'failure_code', 'failure_description', 'occurred_at', 'metadata',
    ];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'occurred_at' => 'datetime', 'metadata' => 'array'];
    }
}
