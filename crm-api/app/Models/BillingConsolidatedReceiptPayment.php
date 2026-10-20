<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BillingConsolidatedReceiptPayment extends Model
{
    protected $fillable = [
        'billing_consolidated_receipt_id', 'deal_payment_id', 'amount_snapshot', 'payment_snapshot',
    ];

    protected function casts(): array
    {
        return ['amount_snapshot' => 'decimal:2', 'payment_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payments represented by an issued consolidated receipt are immutable.'));
        static::deleting(fn () => throw new LogicException('Payments represented by an issued consolidated receipt are immutable.'));
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(BillingConsolidatedReceipt::class, 'billing_consolidated_receipt_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(DealPayment::class, 'deal_payment_id');
    }
}
