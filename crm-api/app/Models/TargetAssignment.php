<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TargetAssignment extends Model
{
    protected $fillable = [
        'organization_id', 'target_definition_id', 'scope_type', 'user_id', 'department_id',
        'period_type', 'period_start', 'period_end', 'target_value', 'status',
        'definition_code', 'definition_name', 'unit_type', 'unit_label', 'metric_source',
        'direction', 'assigned_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'target_value' => 'decimal:4',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(TargetDefinition::class, 'target_definition_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ManualTargetEntry::class);
    }
}
