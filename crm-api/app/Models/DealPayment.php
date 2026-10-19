<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DealPayment extends Model
{
    protected $fillable = [
        'deal_id',
        'organization_id',
        'created_by',
        'amount',
        'payment_date',
        'payment_mode',
        'txn_or_utr_number',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function deal(): BelongsTo
    {
        // A payment remains an auditable financial record after its deal is archived.
        return $this->belongsTo(Deal::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function billingAllocations(): HasMany
    {
        return $this->hasMany(BillingPaymentAllocation::class);
    }
}
