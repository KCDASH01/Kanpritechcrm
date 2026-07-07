<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\LeadTimeline;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'created_by', 'assigned_to', 'lead_date',
        'pipeline_id', 'stage_id',
        'first_name', 'last_name', 'email', 'phone',
        'company', 'job_title', 'website', 'status', 'source',
        'industry', 'city', 'country', 'notes', 'score',
        'custom_fields', 'external_lead_id', 'lost_reason',
    ];

    protected function casts(): array
    {
        return [
            'lead_date'     => 'date',
            'custom_fields' => 'array',
            'score'         => 'integer',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function createdBy(): BelongsTo    { return $this->belongsTo(User::class, 'created_by'); }
    public function assignedTo(): BelongsTo   { return $this->belongsTo(User::class, 'assigned_to'); }
    public function pipeline(): BelongsTo     { return $this->belongsTo(Pipeline::class); }
    public function stage(): BelongsTo        { return $this->belongsTo(Stage::class); }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(LeadTimeline::class)->latest();
    }

    // ── Computed ───────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
