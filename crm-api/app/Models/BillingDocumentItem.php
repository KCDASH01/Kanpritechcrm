<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BillingDocumentItem extends Model
{
    protected $fillable = [
        'billing_document_id', 'catalogue_item_id', 'line_number', 'description', 'hsn_sac', 'quantity', 'unit',
        'unit_price', 'discount_type', 'discount_value', 'discount_amount', 'taxable_amount',
        'gst_rate', 'cgst_amount', 'sgst_amount', 'igst_amount', 'line_total', 'metadata', 'catalogue_snapshot',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'catalogue_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $document = $item->document()->first();
            if ($document?->issued_at !== null) {
                throw new LogicException('Line items on issued billing documents are immutable.');
            }
        });
        static::deleting(function (self $item): void {
            if ($item->document()->whereNotNull('issued_at')->exists()) {
                throw new LogicException('Line items on issued billing documents are immutable.');
            }
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BillingDocument::class, 'billing_document_id');
    }

    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(BillingCatalogueItem::class, 'catalogue_item_id')->withTrashed();
    }
}
