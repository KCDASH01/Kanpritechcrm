<?php

namespace App\Http\Requests\Lead;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $website = $this->input('website');
        if (is_string($website)) {
            $website = trim($website);
            if ($website === '') {
                $this->merge(['website' => null]);
            } elseif (! preg_match('#^https?://#i', $website)) {
                $this->merge(['website' => 'https://'.$website]);
            }
        }

        foreach (['client_id', 'assigned_to', 'department_id', 'expected_value', 'recurring_amount', 'recurring_end_date', 'next_billing_date', 'billing_cycles', 'contract_value'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $orgId    = $this->user()?->organization_id;

        return [
            'client_type'       => [$isUpdate ? 'sometimes' : 'required', 'in:NEW,EXISTING'],
            'client_id'         => [Rule::requiredIf($this->input('client_type') === 'EXISTING'), 'nullable', 'integer', Rule::exists('clients', 'id')->where(fn ($q) => $q->where('organization_id', $orgId)->whereNull('deleted_at'))],
            'business_type'     => [$isUpdate ? 'sometimes' : 'required', 'in:ONE_TIME,RECURRING'],
            'market_type'       => [$isUpdate ? 'sometimes' : 'required', 'in:DOMESTIC,INTERNATIONAL'],
            'first_name'        => [$isUpdate ? 'sometimes' : Rule::requiredIf($this->input('client_type', 'NEW') === 'NEW'), 'string', 'max:191'],
            'last_name'         => ['nullable', 'string', 'max:191'],
            'email'             => ['nullable', 'email', 'max:191'],
            'phone'             => ['nullable', 'string', 'max:30'],
            'company'           => ['nullable', 'string', 'max:191'],
            'job_title'         => ['nullable', 'string', 'max:191'],
            'website'           => ['nullable', 'url', 'max:500'],
            'status'            => ['nullable', 'in:new,contacted,ringing,important,converted,lost,followup,meeting,not_interested'],
            'schedule_at'       => [
                Rule::requiredIf(function () use ($isUpdate) {
                    if (! in_array($this->input('status'), ['followup', 'meeting'], true)) {
                        return false;
                    }

                    if (! $isUpdate) {
                        return true;
                    }

                    $lead = $this->route('lead');

                    return $lead && $lead->status !== $this->input('status');
                }),
                'nullable',
                'date',
            ],
            'remark'            => ['nullable', 'string', 'max:2000'],
            'source'            => ['nullable', 'in:manual,sso_import,web_form,csv,api,other,meta_ad,email_campaign,google_ads'],
            'types'             => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'in:webapp_development,mobile_app_development,website_development,digital_marketing,others',
            ],
            'industry'          => ['nullable', 'string', 'max:64'],
            'city'              => ['nullable', 'string', 'max:191'],
            'state'             => ['nullable', 'string', 'max:191'],
            'country'           => ['nullable', 'string', 'max:191'],
            'notes'             => ['nullable', 'string'],
            'score'             => ['nullable', 'integer', 'min:0', 'max:100'],
            'pipeline_id'       => ['nullable', 'integer', 'exists:pipelines,id'],
            'stage_id'          => ['nullable', 'integer', 'exists:stages,id'],
            'department_id'     => ['nullable', 'integer', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('organization_id', $orgId)->whereNull('deleted_at'))],
            'assigned_to'       => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(function ($query) use ($orgId) {
                    $query->where('organization_id', $orgId)
                          ->where('role', 'employee')
                          ->where('is_active', true);
                }),
            ],
            'custom_fields'     => ['nullable', 'array'],
            'external_lead_id'  => ['nullable', 'string', 'max:191'],
            'lost_reason'       => ['nullable', 'string', 'max:191'],
            'expected_value'    => ['nullable', 'numeric', 'min:0'],
            'currency'          => ['nullable', 'in:INR,USD'],
            'recurring_frequency' => [Rule::requiredIf($this->input('business_type') === 'RECURRING'), 'nullable', 'in:MONTHLY,QUARTERLY,HALF_YEARLY,YEARLY'],
            'recurring_amount'  => [Rule::requiredIf($this->input('business_type') === 'RECURRING'), 'nullable', 'numeric', 'gt:0'],
            'recurring_start_date' => [Rule::requiredIf($this->input('business_type') === 'RECURRING'), 'nullable', 'date'],
            'recurring_end_type' => [Rule::requiredIf($this->input('business_type') === 'RECURRING'), 'nullable', 'in:ONGOING,FIXED'],
            'recurring_end_date' => [Rule::requiredIf($this->input('business_type') === 'RECURRING' && $this->input('recurring_end_type') === 'FIXED'), 'nullable', 'date', 'after_or_equal:recurring_start_date'],
            'next_billing_date' => ['nullable', 'date', 'after_or_equal:recurring_start_date'],
            'billing_cycles'    => ['nullable', 'integer', 'min:1'],
            'contract_value'    => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
