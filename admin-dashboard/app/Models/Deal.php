<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deal extends Model
{
    protected $table = 'deals';

    protected $fillable = [
        'organization_id',
        'assigned_to',
        'status',
        'value',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'value'     => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
