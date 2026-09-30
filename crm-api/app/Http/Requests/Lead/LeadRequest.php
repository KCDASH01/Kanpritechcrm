<?php

namespace App\Http\Requests\Lead;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Lead;
use App\Support\PhoneNormalizer;

class LeadRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $website = $this->input('website');
        if (! is_string($website)) {
            return;
        }

        $website = trim($website);
        if ($website === '') {
            $this->merge(['website' => null]);
            return;
        }

        if (! preg_match('#^https?://#i', $website)) {
            $this->merge(['website' => 'https://'.$website]);
        }
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $orgId    = $this->user()?->organization_id;
        $leadId   = $this->route('lead')?->id;

        return [
            'first_name'        => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:191'],
            'last_name'         => ['nullable', 'string', 'max:191'],
            'email'             => ['nullable', 'email', 'max:191'],
            'phone'             => [
                'nullable',
                'string',
                'max:30',
                function (string $attribute, mixed $value, \Closure $fail) use ($orgId, $leadId): void {
                    $normalized = PhoneNormalizer::normalize(is_string($value) ? $value : null);

                    if (! $normalized || ! $orgId) {
                        return;
                    }

                    $duplicate = Lead::with('assignedTo:id,name')
                        ->where('organization_id', $orgId)
                        ->where('phone_normalized', $normalized)
                        ->when($leadId, fn ($query) => $query->where('id', '!=', $leadId))
                        ->first();

                    if (! $duplicate) {
                        return;
                    }

                    $assigneeName = $duplicate->assignedTo?->name ?? 'Unassigned';
                    $fail("This lead is already assigned to {$assigneeName}");
                },
            ],
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
