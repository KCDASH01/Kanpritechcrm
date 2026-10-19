<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillingBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'account_name' => ['required', 'string', 'max:255'], 'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:64'], 'ifsc' => ['nullable', 'string', 'max:20'],
            'swift' => ['nullable', 'string', 'max:20'], 'upi_id' => ['nullable', 'string', 'max:255'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', Rule::in(['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD'])],
            'is_default' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
