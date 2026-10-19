<?php

namespace App\Http\Requests\Billing;

use App\Models\BillingDocumentSequence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBillingSequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'prefix' => ['required', 'string', 'max:40', 'regex:/^(?:[^{}]|\{(?:FY|YYYY|YY)\})*$/'],
            'suffix' => ['nullable', 'string', 'max:40', 'regex:/^(?:[^{}]|\{(?:FY|YYYY|YY)\})*$/'],
            'starting_number' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'next_number' => ['required', 'integer', 'gte:starting_number', 'max:999999999999'],
            'padding' => ['required', 'integer', 'between:1,12'],
            'numbering_period' => ['required', Rule::in(BillingDocumentSequence::PERIODS)],
            'reset_on_period_change' => ['required', 'boolean'],
        ];
    }
}
