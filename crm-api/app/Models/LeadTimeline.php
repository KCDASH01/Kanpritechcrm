<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadTimeline extends Model
{
    protected $table = 'lead_timeline';

    protected $fillable = [
        'lead_id',
        'organization_id',
        'user_id',
        'action',
        'description',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Static helper — log any lead action ───────────────────────────────────

    public static function log(
        Lead   $lead,
        string $action,
        string $description,
        ?int   $userId = null,
        array  $meta   = []
    ): self {
        return self::create([
            'lead_id'         => $lead->id,
            'organization_id' => $lead->organization_id,
            'user_id'         => $userId,
            'action'          => $action,
            'description'     => $description,
            'meta'            => empty($meta) ? null : $meta,
        ]);
    }
}
