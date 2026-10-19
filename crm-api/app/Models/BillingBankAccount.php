<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingBankAccount extends Model
{
    protected $fillable = [
        'organization_id', 'billing_profile_id', 'account_name', 'bank_name',
        'account_number', 'ifsc', 'swift', 'upi_id', 'branch_name', 'currency',
        'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(BillingProfile::class, 'billing_profile_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
