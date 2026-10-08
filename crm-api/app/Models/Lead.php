<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\LeadTimeline;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\PhoneNormalizer;

class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'client_id', 'created_by', 'assigned_to', 'department_id', 'lead_date',
        'pipeline_id', 'stage_id',
        'first_name', 'last_name', 'email', 'phone', 'phone_normalized',
        'company', 'job_title', 'website', 'status', 'source', 'types',
        'industry', 'city', 'state', 'country', 'notes', 'score',
        'client_type', 'business_type', 'market_type', 'expected_value', 'currency',
        'recurring_frequency', 'recurring_amount', 'recurring_start_date',
        'recurring_end_type', 'recurring_end_date', 'next_billing_date',
        'billing_cycles', 'contract_value',
        'custom_fields', 'external_lead_id', 'lost_reason',
    ];

    protected function casts(): array
    {
        return [
            'lead_date'     => 'date',
            'custom_fields' => 'array',
            'score'         => 'integer',
            'expected_value' => 'decimal:2',
            'recurring_amount' => 'decimal:2',
            'contract_value' => 'decimal:2',
            'recurring_start_date' => 'date',
            'recurring_end_date' => 'date',
            'next_billing_date' => 'date',
            'billing_cycles' => 'integer',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function client(): BelongsTo       { return $this->belongsTo(Client::class); }
    public function createdBy(): BelongsTo    { return $this->belongsTo(User::class, 'created_by'); }
    public function assignedTo(): BelongsTo   { return $this->belongsTo(User::class, 'assigned_to'); }
    public function department(): BelongsTo   { return $this->belongsTo(Department::class); }
    public function pipeline(): BelongsTo     { return $this->belongsTo(Pipeline::class); }
    public function stage(): BelongsTo        { return $this->belongsTo(Stage::class); }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function recurringBusinesses(): HasMany
    {
        return $this->hasMany(RecurringBusiness::class);
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

    public function setPhoneAttribute(?string $value): void
    {
        $phone = is_string($value) ? trim($value) : $value;
        $phone = $phone === '' ? null : $phone;

        $this->attributes['phone'] = $phone;
        $this->attributes['phone_normalized'] = PhoneNormalizer::normalize($phone);
    }
}
