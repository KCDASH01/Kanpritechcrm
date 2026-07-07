<?php

namespace App\Http\Requests\Lead;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $orgId    = $this->user()?->organization_id;

        return [
            'first_name'        => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:191'],
            'last_name'         => ['nullable', 'string', 'max:191'],
            'email'             => ['nullable', 'email', 'max:191'],
            'phone'             => ['nullable', 'string', 'max:30'],
            'company'           => ['nullable', 'string', 'max:191'],
            'job_title'         => ['nullable', 'string', 'max:191'],
            'website'           => ['nullable', 'url', 'max:500'],
            'status'            => ['nullable', 'in:new,contacted,qualified,unqualified,converted,lost,followup,meeting,not_interested'],
            'schedule_at'       => [
                Rule::requiredIf(fn () => in_array($this->input('status'), ['followup', 'meeting'], true)),
                'nullable',
                'date',
            ],
            'source'            => ['nullable', 'in:manual,sso_import,web_form,csv,api,other,meta_ad,email_campaign,google_ads'],
            'industry'          => ['nullable', 'string', 'max:64'],
            'city'              => ['nullable', 'string', 'max:191'],
            'country'           => ['nullable', 'string', 'max:191'],
            'notes'             => ['nullable', 'string'],
            'score'             => ['nullable', 'integer', 'min:0', 'max:100'],
            'pipeline_id'       => ['nullable', 'integer', 'exists:pipelines,id'],
            'stage_id'          => ['nullable', 'integer', 'exists:stages,id'],
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
        ];
    }
}
