<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Proposal extends Model
{
    protected $fillable = [
        'organization_id',
        'lead_id',
        'created_by',
        'title',
        'theme',
        'conversation_notes',
        'content',
        'status',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'content'     => 'array',
            'valid_until' => 'date',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
