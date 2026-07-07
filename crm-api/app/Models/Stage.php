<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stage extends Model
{
    protected $fillable = [
        'pipeline_id', 'organization_id', 'name', 'color',
        'sort_order', 'probability', 'is_won', 'is_lost',
    ];

    protected function casts(): array
    {
        return [
            'is_won'      => 'boolean',
            'is_lost'     => 'boolean',
            'probability' => 'decimal:2',
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
