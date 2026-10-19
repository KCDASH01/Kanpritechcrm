<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BillingReceipt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issuer_snapshot' => 'array', 'customer_snapshot' => 'array', 'payment_snapshot' => 'array', 'issued_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $receipt): void {
            if ($receipt->isDirty(['organization_id', 'client_id', 'deal_payment_id', 'receipt_number', 'issuer_snapshot', 'customer_snapshot', 'payment_snapshot', 'status', 'issued_at', 'issued_by'])) {
                throw new LogicException('Issued billing receipts are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Issued billing receipts cannot be deleted.'));
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(DealPayment::class, 'deal_payment_id');
    }
}
