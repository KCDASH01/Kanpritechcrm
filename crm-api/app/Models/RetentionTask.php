<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetentionTask extends Model
{
    protected $fillable = ['organization_id', 'client_id', 'recurring_business_id', 'assigned_to', 'task_type', 'status', 'due_date', 'reason', 'notes', 'completed_at', 'fingerprint'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'completed_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function recurringBusiness(): BelongsTo
    {
        return $this->belongsTo(RecurringBusiness::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
