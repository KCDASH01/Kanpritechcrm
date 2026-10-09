<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTarget extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'target_type',
        'target_amount',
        'receivable_amount',
        'received_amount',
        'notes',
        'period_start',
        'period_end',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'target_amount'     => 'decimal:2',
            'receivable_amount' => 'decimal:2',
            'received_amount'   => 'decimal:2',
            'period_start'      => 'date',
            'period_end'        => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
