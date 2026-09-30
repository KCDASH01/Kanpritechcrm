<?php

namespace App\Http\Requests\Deal;

use Illuminate\Foundation\Http\FormRequest;

class DealRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        // Convert empty strings to null for nullable fields so MySQL
        // date/string columns are never sent an empty string.
        $nullables = ['expected_close_date', 'closed_at', 'lost_reason', 'description', 'negotiation_notes', 'received_at'];
        $patch = [];
        foreach ($nullables as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $patch[$field] = null;
            }
        }
        if ($patch) {
            $this->merge($patch);
        }
    }

    public function rules(): array
    {
        return [
            'title'               => ['required', 'string', 'max:191'],
            'lead_id'             => ['nullable', 'integer', 'exists:leads,id'],
            'pipeline_id'         => ['required', 'integer', 'exists:pipelines,id'],
            'stage_id'            => ['required', 'integer', 'exists:stages,id'],
            'assigned_to'         => ['nullable', 'integer', 'exists:users,id'],
            'value'               => ['nullable', 'numeric', 'min:0'],
            'currency'            => ['nullable', 'in:INR,USD'],
            'status'              => ['nullable', 'in:open,won,lost'],
            'expected_close_date' => ['nullable', 'date'],
            'closed_at'           => ['nullable', 'date'],
            'description'         => ['nullable', 'string'],
            'probability'         => ['nullable', 'integer', 'min:0', 'max:100'],
            'custom_fields'       => ['nullable', 'array'],
            'lost_reason'         => ['nullable', 'string', 'max:191'],
            'original_value'      => ['nullable', 'numeric', 'min:0'],
            'counter_offer_value' => ['nullable', 'numeric', 'min:0'],
            'negotiation_notes'   => ['nullable', 'string'],
            'handed_off_at'       => ['nullable', 'string'],
            'received_amount'     => ['nullable', 'numeric', 'min:0'],
            'received_at'         => ['nullable', 'date'],
        ];
    }
}
