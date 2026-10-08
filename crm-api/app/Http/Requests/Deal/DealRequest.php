<?php

namespace App\Http\Requests\Deal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $orgId = $this->user()?->organization_id;
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
            'client_id'           => ['nullable', 'integer', Rule::exists('clients', 'id')->where(fn ($q) => $q->where('organization_id', $orgId)->whereNull('deleted_at'))],
            'department_id'       => ['nullable', 'integer', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('organization_id', $orgId)->whereNull('deleted_at'))],
            'client_type'         => ['nullable', 'in:NEW,EXISTING'],
            'business_type'       => ['nullable', 'in:ONE_TIME,RECURRING'],
            'market_type'         => ['nullable', 'in:DOMESTIC,INTERNATIONAL'],
            'service_type'        => ['nullable', 'string', 'max:64'],
            'recurring_frequency' => ['nullable', 'in:MONTHLY,QUARTERLY,HALF_YEARLY,YEARLY'],
            'recurring_amount'    => ['nullable', 'numeric', 'gt:0'],
            'recurring_start_date'=> ['nullable', 'date'],
            'recurring_end_date'  => ['nullable', 'date', 'after_or_equal:recurring_start_date'],
            'next_billing_date'   => ['nullable', 'date', 'after_or_equal:recurring_start_date'],
            'billing_cycles'      => ['nullable', 'integer', 'min:1'],
            'contract_value'      => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
