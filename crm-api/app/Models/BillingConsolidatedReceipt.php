<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class BillingConsolidatedReceipt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issuer_snapshot' => 'array', 'customer_snapshot' => 'array', 'invoice_snapshot' => 'array', 'issued_at' => 'datetime', 'period_start' => 'date', 'period_end' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $receipt): void {
            if ($receipt->isDirty(['organization_id', 'client_id', 'billing_document_id', 'receipt_number', 'period_start', 'period_end', 'represented_total', 'issuer_snapshot', 'customer_snapshot', 'invoice_snapshot', 'status', 'issued_at', 'issued_by'])) {
                throw new LogicException('Issued consolidated receipts are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Issued consolidated receipts cannot be deleted.'));
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingConsolidatedReceiptPayment::class, 'billing_consolidated_receipt_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BillingDocument::class, 'billing_document_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }
}
