<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'lead_id', 'pipeline_id', 'stage_id',
        'created_by', 'assigned_to',
        'title', 'value', 'currency', 'status', 'lost_reason',
        'original_value', 'counter_offer_value', 'negotiation_notes',
        'expected_close_date', 'closed_at', 'handed_off_at',
        'description', 'probability', 'custom_fields',
        'received_amount', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'value'               => 'decimal:2',
            'original_value'      => 'decimal:2',
            'counter_offer_value' => 'decimal:2',
            'received_amount'      => 'decimal:2',
            'received_at'          => 'date',
            'expected_close_date'  => 'date',
            'closed_at'            => 'date',
            'handed_off_at'       => 'datetime',
            'probability'         => 'integer',
            'custom_fields'       => 'array',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function lead(): BelongsTo         { return $this->belongsTo(Lead::class); }
    public function pipeline(): BelongsTo     { return $this->belongsTo(Pipeline::class); }
    public function stage(): BelongsTo        { return $this->belongsTo(Stage::class); }
    public function createdBy(): BelongsTo    { return $this->belongsTo(User::class, 'created_by'); }
    public function assignedTo(): BelongsTo   { return $this->belongsTo(User::class, 'assigned_to'); }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DealPayment::class);
    }
}
