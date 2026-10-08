<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringBusiness extends Model
{
    protected $fillable = [
        'organization_id', 'client_id', 'lead_id', 'deal_id', 'assigned_to', 'department_id',
        'created_by', 'business_name', 'service_type', 'amount', 'currency', 'frequency',
        'start_date', 'end_date', 'next_billing_date', 'billing_cycles', 'contract_value',
        'status', 'notes', 'paused_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'contract_value' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'next_billing_date' => 'date',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'billing_cycles' => 'integer',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function deal(): BelongsTo { return $this->belongsTo(Deal::class); }
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
