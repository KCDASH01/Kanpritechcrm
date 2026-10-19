<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use LogicException;

class BillingDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'organization_id', 'client_id', 'lead_id', 'deal_id', 'recurring_business_id',
        'source_document_id', 'converted_to_document_id', 'document_type', 'status', 'payment_status', 'delivery_status', 'document_number', 'issue_date',
        'due_date', 'currency', 'issuer_snapshot', 'customer_snapshot', 'tax_context',
        'subtotal', 'discount_total', 'taxable_total', 'cgst_total', 'sgst_total',
        'igst_total', 'rounding_adjustment', 'grand_total', 'template', 'terms', 'notes',
        'footer', 'submitted_at', 'approved_at', 'approved_by', 'voided_at', 'voided_by', 'void_reason',
        'issued_at', 'issued_by', 'created_by', 'assigned_to', 'pdf_path', 'pdf_sha256', 'version', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'issuer_snapshot' => 'array', 'customer_snapshot' => 'array', 'tax_context' => 'array',
            'issue_date' => 'date', 'due_date' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime',
            'voided_at' => 'datetime', 'issued_at' => 'datetime',
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'taxable_total' => 'decimal:2',
            'cgst_total' => 'decimal:2', 'sgst_total' => 'decimal:2', 'igst_total' => 'decimal:2',
            'rounding_adjustment' => 'decimal:2', 'grand_total' => 'decimal:2', 'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $document): void {
            $document->uuid ??= (string) Str::uuid();
        });

        static::updating(function (self $document): void {
            if ($document->getOriginal('issued_at') !== null && $document->isDirty([
                'document_number', 'document_type', 'organization_id', 'currency', 'issuer_snapshot',
                'customer_snapshot', 'tax_context', 'issue_date', 'due_date', 'template', 'terms',
                'notes', 'footer', 'subtotal', 'discount_total', 'taxable_total', 'cgst_total',
                'sgst_total', 'igst_total', 'rounding_adjustment', 'grand_total',
            ])) {
                throw new LogicException('Issued billing documents are immutable. Void and reissue instead.');
            }
        });

        static::deleting(function (self $document): void {
            if ($document->issued_at !== null) {
                throw new LogicException('Issued billing documents cannot be deleted.');
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class)->withTrashed();
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_document_id');
    }

    public function convertedToDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'converted_to_document_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingDocumentItem::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BillingPaymentAllocation::class);
    }
}
