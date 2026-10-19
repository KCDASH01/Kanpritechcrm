<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingDocumentSequence extends Model
{
    public const TYPES = ['proforma', 'invoice', 'payment_receipt', 'consolidated_receipt'];

    public const PERIODS = ['continuous', 'calendar_year', 'financial_year'];

    protected $fillable = [
        'organization_id', 'document_type', 'prefix', 'suffix', 'starting_number',
        'next_number', 'padding', 'numbering_period', 'last_period_key',
        'reset_on_period_change', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'starting_number' => 'integer', 'next_number' => 'integer', 'padding' => 'integer',
            'reset_on_period_change' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
