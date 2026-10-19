<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalculateBillingDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'interstate' => ['required', 'boolean'], 'gst_enabled' => ['required', 'boolean'],
            'rounding_scale' => ['sometimes', Rule::in([0, 2])], 'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.description' => ['required', 'string', 'max:1000'], 'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'], 'items.*.gst_rate' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }
}
