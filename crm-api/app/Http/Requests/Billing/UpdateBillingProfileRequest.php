<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBillingProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['nullable', 'string', 'max:255'], 'trade_name' => ['nullable', 'string', 'max:255'],
            'registered_address' => ['nullable', 'string', 'max:2000'], 'billing_address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:120'], 'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'], 'country' => ['required', 'string', 'size:2'],
            'contact_email' => ['nullable', 'email', 'max:255'], 'contact_phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'url', 'max:255'], 'gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]Z[A-Z0-9]$/'],
            'pan' => ['nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'], 'tax_registrations' => ['nullable', 'array'],
            'default_currency' => ['required', Rule::in(['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD'])],
            'supported_currencies' => ['required', 'array', 'min:1'],
            'supported_currencies.*' => [Rule::in(['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD'])],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'approval_required' => ['sometimes', 'boolean'],
            'default_notes' => ['nullable', 'string', 'max:5000'], 'default_terms' => ['nullable', 'string', 'max:10000'],
            'default_payment_link' => ['nullable', 'url', 'max:2048'], 'document_footer' => ['nullable', 'string', 'max:2000'],
            'default_template' => ['required', Rule::in(['classic', 'modern', 'minimal', 'corporate', 'executive'])],
            'financial_year_start_month' => ['required', 'integer', 'between:1,12'],
            'tax_settings' => ['nullable', 'array'],
            'tax_settings.gst_enabled' => ['sometimes', 'boolean'],
            'tax_settings.default_gst_rate' => ['sometimes', 'numeric', 'between:0,100'],
            'tax_settings.rounding_scale' => ['sometimes', Rule::in([0, 2])],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['gstin', 'pan', 'country', 'default_currency'] as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $this->merge([$key => strtoupper(trim($this->input($key)))]);
            }
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! in_array($this->input('default_currency'), $this->input('supported_currencies', []), true)) {
                $validator->errors()->add('supported_currencies', 'Supported currencies must include the default currency.');
            }
        });
    }
}
