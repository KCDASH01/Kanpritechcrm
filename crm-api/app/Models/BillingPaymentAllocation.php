<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPaymentAllocation extends Model
{
    protected $fillable = [
        'organization_id', 'billing_document_id', 'deal_payment_id', 'amount', 'allocated_on',
        'allocated_by', 'reversed_at', 'reversed_by', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'allocated_on' => 'date', 'reversed_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BillingDocument::class, 'billing_document_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(DealPayment::class, 'deal_payment_id');
    }
}
