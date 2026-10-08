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
        'organization_id', 'client_id', 'lead_id', 'pipeline_id', 'stage_id',
        'created_by', 'assigned_to', 'department_id',
        'title', 'value', 'currency', 'status', 'lost_reason',
        'client_type', 'business_type', 'market_type', 'service_type',
        'recurring_frequency', 'recurring_amount', 'recurring_start_date',
        'recurring_end_date', 'next_billing_date', 'billing_cycles', 'contract_value',
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
            'recurring_amount'    => 'decimal:2',
            'contract_value'      => 'decimal:2',
            'recurring_start_date'=> 'date',
            'recurring_end_date'  => 'date',
            'next_billing_date'   => 'date',
            'billing_cycles'      => 'integer',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function client(): BelongsTo       { return $this->belongsTo(Client::class); }
    public function lead(): BelongsTo         { return $this->belongsTo(Lead::class); }
    public function pipeline(): BelongsTo     { return $this->belongsTo(Pipeline::class); }
    public function stage(): BelongsTo        { return $this->belongsTo(Stage::class); }
    public function createdBy(): BelongsTo    { return $this->belongsTo(User::class, 'created_by'); }
    public function assignedTo(): BelongsTo   { return $this->belongsTo(User::class, 'assigned_to'); }
    public function department(): BelongsTo   { return $this->belongsTo(Department::class); }
    public function recurringBusiness()
    {
        return $this->hasOne(RecurringBusiness::class);
    }

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
