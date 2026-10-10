<?php

namespace App\Services\LeadIntegrations;

use App\Models\IntegrationEvent;
use App\Models\IntegrationReviewItem;
use App\Models\Lead;
use App\Models\LeadAttribution;
use App\Models\LeadTimeline;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class IntegrationLeadService
{
    public function __construct(private readonly LeadRoutingService $routing) {}

    public function process(IntegrationEvent $event, array $data): IntegrationEvent
    {
        return DB::transaction(function () use ($event, $data): IntegrationEvent {
            $event = IntegrationEvent::query()->lockForUpdate()->findOrFail($event->id);
            if (in_array($event->status, ['imported', 'linked', 'ignored'], true)) {
                return $event;
            }

            $event->increment('attempts');
            $event->update(['extracted_data' => $this->redactedExtract($data)]);

            if ($this->isNonLeadReply($event, $data)) {
                $event->update([
                    'status' => 'ignored',
                    'classification' => $data['classification'] ?? null,
                    'processed_at' => now(),
                ]);

                return $event->fresh();
            }

            if (! $event->organization_id) {
                return $this->review($event, 'authorization_issue', $data);
            }

            $lead = $this->findExistingLead($event->organization_id, $data, $event);
            $reviewAssignee = isset($data['_review_assigned_to'])
                ? User::query()->whereKey($data['_review_assigned_to'])->where('organization_id', $event->organization_id)->where('is_active', true)->value('id')
                : null;
            $route = $lead
                ? ['user_id' => $lead->assigned_to, 'department_id' => $lead->department_id, 'reason' => 'existing_lead_owner']
                : ($reviewAssignee
                    ? ['user_id' => $reviewAssignee, 'department_id' => null, 'reason' => 'manual_review']
                    : $this->routing->resolve($event->organization_id, array_merge($data, ['provider' => $event->provider])));

            if ($lead) {
                $this->attribute($event, $lead, $data);
                LeadTimeline::log($lead, 'integration_interaction', $this->timelineDescription($event, $data), null, [
                    'integration_event_id' => $event->id,
                    'provider' => $event->provider,
                    'classification' => $data['classification'] ?? null,
                ]);
                $event->update([
                    'lead_id' => $lead->id,
                    'assigned_to' => $lead->assigned_to,
                    'assignment_reason' => 'existing_lead_owner',
                    'classification' => $data['classification'] ?? null,
                    'status' => 'linked',
                    'processed_at' => now(),
                ]);
                $this->notify($event, $lead, false);

                return $event->fresh();
            }

            $payload = $this->leadPayload($event, $data, $route);
            $missing = $this->missingRequired($payload);
            if ($missing !== []) {
                return $this->review($event, 'missing_required_fields', $data, ['missing' => $missing]);
            }
            if (! $route['user_id']) {
                return $this->review($event, 'ambiguous_assignment', $data);
            }
            if ($this->leadLimitReached($event->organization_id)) {
                return $this->review($event, 'plan_limit_reached', $data);
            }

            $lead = Lead::create($payload);
            LeadTimeline::log($lead, 'created', 'Lead created by Lead Integrations', null, [
                'integration_event_id' => $event->id,
                'provider' => $event->provider,
            ]);
            $this->attribute($event, $lead, $data);
            $event->update([
                'lead_id' => $lead->id,
                'assigned_to' => $lead->assigned_to,
                'assignment_reason' => $route['reason'],
                'classification' => $data['classification'] ?? null,
                'status' => 'imported',
                'processed_at' => now(),
            ]);
            $this->notify($event, $lead, true);

            return $event->fresh();
        }, 3);
    }

    private function findExistingLead(int $organizationId, array $data, IntegrationEvent $event): ?Lead
    {
        if (! empty($data['lead_id'])) {
            $linked = Lead::query()->where('organization_id', $organizationId)->find($data['lead_id']);
            if ($linked) {
                return $linked;
            }
        }

        $externalId = $data['external_lead_id'] ?? null;
        if ($externalId) {
            $leadId = LeadAttribution::query()
                ->where('organization_id', $organizationId)
                ->where('platform', $event->provider)
                ->where('external_lead_id', $externalId)
                ->value('lead_id');
            if ($leadId) {
                return Lead::query()->where('organization_id', $organizationId)->find($leadId);
            }
        }

        $email = isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null;
        $phone = PhoneNormalizer::normalize($data['phone'] ?? null);
        if (! $email && ! $phone) {
            return null;
        }

        return Lead::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($email, $phone): void {
                if ($email) {
                    $query->whereRaw('LOWER(email) = ?', [$email]);
                }
                if ($phone) {
                    $email ? $query->orWhere('phone_normalized', $phone) : $query->where('phone_normalized', $phone);
                }
            })
            ->oldest('id')
            ->first();
    }

    private function leadPayload(IntegrationEvent $event, array $data, array $route): array
    {
        [$firstName, $lastName] = $this->splitName((string) ($data['name'] ?? $data['full_name'] ?? ''));
        $creator = User::query()
            ->where('organization_id', $event->organization_id)
            ->where('is_active', true)
            ->whereIn('role', ['owner', 'admin'])
            ->oldest('id')
            ->value('id');

        return [
            'organization_id' => $event->organization_id,
            'created_by' => $creator,
            'assigned_to' => $route['user_id'],
            'department_id' => $route['department_id'],
            'lead_date' => now()->toDateString(),
            'first_name' => trim((string) ($data['first_name'] ?? $firstName)),
            'last_name' => trim((string) ($data['last_name'] ?? $lastName)) ?: null,
            'email' => isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null,
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'status' => 'new',
            'source' => in_array($event->provider, ['meta', 'whatsapp'], true) ? 'meta_ad' : 'email_campaign',
            'types' => $this->normalizeType($data['types'] ?? $data['service_type'] ?? $data['service'] ?? null),
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'country' => $data['country'] ?? null,
            'notes' => $data['notes'] ?? null,
            'client_type' => isset($data['client_type']) ? mb_strtoupper((string) $data['client_type']) : null,
            'business_type' => isset($data['business_type']) ? mb_strtoupper((string) $data['business_type']) : null,
            'market_type' => isset($data['market_type']) ? mb_strtoupper((string) $data['market_type']) : null,
            'currency' => $data['currency'] ?? null,
            'expected_value' => $data['expected_value'] ?? null,
            'external_lead_id' => $data['external_lead_id'] ?? null,
            'custom_fields' => [
                'lead_integration' => [
                    'provider' => $event->provider,
                    'event_id' => $event->id,
                    'campaign_id' => $data['campaign_id'] ?? null,
                    'form_id' => $data['form_id'] ?? null,
                    'answers' => $data['form_answers'] ?? null,
                ],
            ],
        ];
    }

    private function missingRequired(array $payload): array
    {
        $required = ['created_by', 'assigned_to', 'first_name', 'types', 'client_type', 'business_type', 'market_type'];
        $missing = [];
        foreach ($required as $field) {
            if (! isset($payload[$field]) || $payload[$field] === '') {
                $missing[] = $field;
            }
        }
        if (empty($payload['email']) && empty($payload['phone'])) {
            $missing[] = 'email_or_phone';
        }
        if (isset($payload['email']) && ! filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            $missing[] = 'valid_email';
        }
        if (isset($payload['types']) && ! in_array($payload['types'], ['webapp_development', 'mobile_app_development', 'website_development', 'digital_marketing', 'others'], true)) {
            $missing[] = 'valid_types';
        }
        if (isset($payload['client_type']) && ! in_array($payload['client_type'], ['NEW', 'EXISTING'], true)) {
            $missing[] = 'valid_client_type';
        }
        if (isset($payload['business_type']) && ! in_array($payload['business_type'], ['ONE_TIME', 'RECURRING'], true)) {
            $missing[] = 'valid_business_type';
        }
        if (isset($payload['market_type']) && ! in_array($payload['market_type'], ['DOMESTIC', 'INTERNATIONAL'], true)) {
            $missing[] = 'valid_market_type';
        }

        return array_values(array_unique($missing));
    }

    private function review(IntegrationEvent $event, string $reason, array $data, array $extra = []): IntegrationEvent
    {
        IntegrationReviewItem::query()->updateOrCreate(
            ['integration_event_id' => $event->id],
            [
                'organization_id' => $event->organization_id,
                'reason' => $reason,
                'status' => 'pending',
                'source_summary' => array_merge([
                    'provider' => $event->provider,
                    'event_type' => $event->event_type,
                ], $extra),
                'extracted_data' => $this->redactedExtract($data),
            ]
        );
        $event->update(['status' => 'review', 'error_message' => $reason, 'processed_at' => now()]);

        return $event->fresh();
    }

    private function attribute(IntegrationEvent $event, Lead $lead, array $data): void
    {
        $original = ! LeadAttribution::query()->where('lead_id', $lead->id)->exists();
        LeadAttribution::query()->firstOrCreate(
            ['integration_event_id' => $event->id],
            [
                'organization_id' => $lead->organization_id,
                'lead_id' => $lead->id,
                'integration_event_id' => $event->id,
                'campaign_id' => $event->campaign_id,
                'platform' => $event->provider,
                'source_account_id' => $data['source_account_id'] ?? null,
                'external_lead_id' => $data['external_lead_id'] ?? null,
                'external_message_id' => $data['external_message_id'] ?? null,
                'campaign_external_id' => $data['campaign_id'] ?? null,
                'adset_external_id' => $data['adset_id'] ?? null,
                'ad_external_id' => $data['ad_id'] ?? null,
                'form_external_id' => $data['form_id'] ?? null,
                'is_original' => $original,
                'captured_at' => $data['captured_at'] ?? now(),
                'metadata' => Arr::only($data, ['campaign_name', 'ad_name', 'form_name', 'classification']),
            ]
        );
    }

    private function notify(IntegrationEvent $event, Lead $lead, bool $created): void
    {
        if (! $lead->assigned_to) {
            return;
        }
        Notification::notify(
            $lead->organization_id,
            $lead->assigned_to,
            'lead_integration',
            $created ? 'New integrated lead assigned' : 'New campaign interaction',
            trim($lead->full_name).' · '.ucfirst($event->provider),
            ['lead_id' => $lead->id, 'integration_event_id' => $event->id],
            '/leads/'.$lead->id
        );
    }

    private function leadLimitReached(int $organizationId): bool
    {
        $organization = Organization::query()->find($organizationId);
        $limit = $organization?->isEnterprisePlan() ? null : ($organization?->isBusinessPlan() ? 5000 : 100);

        return $limit !== null && Lead::query()->where('organization_id', $organizationId)->count() >= $limit;
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function normalizeType(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $normalized = str_replace(['-', ' '], '_', mb_strtolower(trim($value)));

        return match ($normalized) {
            'web_app_development', 'webapp_development' => 'webapp_development',
            'mobile_app_development' => 'mobile_app_development',
            'website', 'website_development' => 'website_development',
            'digital_marketing', 'seo', 'seo_service' => 'digital_marketing',
            'other', 'others' => 'others',
            default => $normalized,
        };
    }

    private function isNonLeadReply(IntegrationEvent $event, array $data): bool
    {
        return $event->event_type === 'email_reply'
            && in_array($data['classification'] ?? null, ['bounce', 'out_of_office', 'unsubscribe', 'not_interested'], true);
    }

    private function redactedExtract(array $data): array
    {
        return Arr::except($data, ['raw_body', 'html_body', 'attachments', 'access_token', 'refresh_token']);
    }

    private function timelineDescription(IntegrationEvent $event, array $data): string
    {
        $classification = $data['classification'] ?? null;

        return 'New '.str_replace('_', ' ', $event->event_type).' received from '.$event->provider
            .($classification ? ' ('.str_replace('_', ' ', $classification).')' : '');
    }
}
