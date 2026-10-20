<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingProfile extends Model
{
    protected $fillable = [
        'organization_id', 'legal_name', 'trade_name', 'logo_path', 'signature_path',
        'registered_address', 'billing_address', 'city', 'state', 'postal_code', 'country',
        'contact_email', 'contact_phone', 'website', 'gstin', 'pan', 'tax_registrations',
        'default_currency', 'supported_currencies', 'payment_terms_days', 'approval_required', 'default_notes', 'default_terms', 'default_payment_link',
        'document_footer', 'default_template', 'financial_year_start_month', 'tax_settings',
        'permissions', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tax_registrations' => 'array',
            'supported_currencies' => 'array',
            'tax_settings' => 'array',
            'permissions' => 'array',
            'payment_terms_days' => 'integer',
            'approval_required' => 'boolean',
            'financial_year_start_month' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BillingBankAccount::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
