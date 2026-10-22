<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TargetDefinition extends Model
{
    protected $fillable = [
        'organization_id', 'code', 'name', 'description', 'unit_type', 'unit_label',
        'metric_source', 'direction', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'target_definition_departments')->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TargetAssignment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
