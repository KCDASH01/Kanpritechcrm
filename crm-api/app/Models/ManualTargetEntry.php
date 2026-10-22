<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualTargetEntry extends Model
{
    protected $fillable = [
        'organization_id', 'target_assignment_id', 'user_id', 'subject_key', 'entry_date',
        'actual_value', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'actual_value' => 'decimal:4'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TargetAssignment::class, 'target_assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
