<?php

namespace App\Models;

use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'created_by', 'assigned_to', 'first_name', 'last_name',
        'company', 'email', 'phone', 'phone_normalized', 'job_title', 'website',
        'city', 'state', 'country',
    ];

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function leads(): HasMany { return $this->hasMany(Lead::class); }
    public function deals(): HasMany { return $this->hasMany(Deal::class); }
    public function recurringBusinesses(): HasMany { return $this->hasMany(RecurringBusiness::class); }

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
