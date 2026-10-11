<?php

namespace App\Jobs;

use App\Models\IntegrationAsset;
use App\Models\IntegrationEvent;
use App\Models\LeadIntegrationSetting;
use App\Services\LeadIntegrations\IntegrationLeadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RetrieveMetaLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $eventId) {}

    public function handle(IntegrationLeadService $service): void
    {
        $event = IntegrationEvent::query()->with('connection')->findOrFail($this->eventId);
        if (in_array($event->status, ['imported', 'linked', 'ignored'], true)) {
            return;
        }
        if (! $event->connection || $event->connection->status !== 'active' || ! $event->connection->access_token) {
            throw new RuntimeException('The Meta Page connection is inactive or its token is unavailable.');
        }

        $leadId = data_get($event->payload, 'leadgen_id');
        if (! $leadId) {
            throw new RuntimeException('Meta webhook did not include a lead identifier.');
        }

        $version = config('services.meta.graph_version');
        if (! $version) {
            throw new RuntimeException('META_GRAPH_VERSION is not configured.');
        }
        $pageId = (string) data_get($event->payload, 'page_id', '');
        $pageToken = $pageId !== '' ? IntegrationAsset::query()->where('connection_id', $event->connection_id)
            ->where('asset_type', 'page')->where('external_id', $pageId)->where('is_selected', true)->first()?->access_token : null;
        $response = Http::retry(3, 500, throw: false)
            ->withToken($pageToken ?: $event->connection->access_token)
            ->get("https://graph.facebook.com/{$version}/{$leadId}", [
                'fields' => 'id,created_time,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,field_data',
            ]);
        if (! $response->successful()) {
            if ($response->status() === 401 || (int) $response->json('error.code') === 190) {
                $event->connection->update(['status' => 'expired', 'last_error' => 'Meta authorization expired or was revoked. Reconnect the Page.']);
            }
            throw new RuntimeException('Meta lead retrieval failed (HTTP '.$response->status().').');
        }

        $payload = $response->json();
        $fields = collect($payload['field_data'] ?? [])->mapWithKeys(function ($row): array {
            return [($row['name'] ?? '') => data_get($row, 'values.0')];
        })->all();
        $mapped = $this->mapFields($fields, (int) $event->organization_id);
        $data = array_merge($mapped, [
            'external_lead_id' => $payload['id'] ?? $leadId,
            'captured_at' => $payload['created_time'] ?? now()->toISOString(),
            'campaign_id' => $payload['campaign_id'] ?? data_get($event->payload, 'campaign_id'),
            'campaign_name' => $payload['campaign_name'] ?? null,
            'adset_id' => $payload['adset_id'] ?? null,
            'ad_id' => $payload['ad_id'] ?? data_get($event->payload, 'ad_id'),
            'ad_name' => $payload['ad_name'] ?? null,
            'form_id' => $payload['form_id'] ?? data_get($event->payload, 'form_id'),
            'form_answers' => $fields,
        ]);
        $event->update(['extracted_data' => $data]);
        $service->process($event->fresh(), $data);
    }

    private function mapFields(array $fields, int $organizationId): array
    {
        $mapped = [
            'name' => $fields['full_name'] ?? $fields['name'] ?? null,
            'first_name' => $fields['first_name'] ?? null,
            'last_name' => $fields['last_name'] ?? null,
            'email' => $fields['email'] ?? null,
            'phone' => $fields['phone_number'] ?? $fields['phone'] ?? null,
            'company' => $fields['company_name'] ?? $fields['company'] ?? null,
            'city' => $fields['city'] ?? null,
            'state' => $fields['state'] ?? null,
            'country' => $fields['country'] ?? null,
            'types' => $fields['service_type'] ?? null,
            'client_type' => $fields['client_type'] ?? null,
            'business_type' => $fields['business_type'] ?? null,
            'market_type' => $fields['market_type'] ?? null,
        ];
        $settings = LeadIntegrationSetting::query()->where('organization_id', $organizationId)->first();
        $configured = (array) data_get($settings?->field_mappings, 'meta', []);
        $allowed = ['name', 'first_name', 'last_name', 'email', 'phone', 'company', 'city', 'state', 'country', 'types', 'client_type', 'business_type', 'market_type'];
        foreach ($configured as $target => $source) {
            if (in_array($target, $allowed, true) && is_string($source) && array_key_exists($source, $fields)) {
                $mapped[$target] = $fields[$source];
            }
        }

        return $mapped;
    }

    public function failed(\Throwable $exception): void
    {
        IntegrationEvent::query()->whereKey($this->eventId)->update([
            'status' => 'failed',
            'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            'processed_at' => now(),
        ]);
    }
}
