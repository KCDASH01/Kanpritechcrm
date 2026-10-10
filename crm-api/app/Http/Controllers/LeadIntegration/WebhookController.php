<?php

namespace App\Http\Controllers\LeadIntegration;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessIntegrationEvent;
use App\Jobs\RetrieveMetaLead;
use App\Jobs\SyncMailbox;
use App\Models\IntegrationCampaignRecipient;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEvent;
use App\Models\IntegrationReviewItem;
use App\Services\LeadIntegrations\EmailLeadParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function verifyMeta(Request $request): Response
    {
        abort_if((string) config('services.meta.webhook_verify_token') === '', 503, 'Meta webhook verification is not configured.');
        abort_unless(
            $request->query('hub_mode') === 'subscribe'
            && hash_equals((string) config('services.meta.webhook_verify_token'), (string) $request->query('hub_verify_token')),
            403
        );

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function meta(Request $request): JsonResponse
    {
        $this->verifySignature($request, (string) config('services.meta.app_secret'));
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) !== 'leadgen') {
                    continue;
                }
                $value = (array) ($change['value'] ?? []);
                $pageId = (string) ($value['page_id'] ?? $entry['id'] ?? '');
                $connection = IntegrationConnection::query()
                    ->where('provider', 'meta')
                    ->where('external_account_id', $pageId)
                    ->where('status', 'active')
                    ->first();
                if (! $connection || empty($value['leadgen_id'])) {
                    continue;
                }
                $externalId = 'leadgen:'.(string) $value['leadgen_id'];
                $event = IntegrationEvent::query()->firstOrCreate(
                    ['provider' => 'meta', 'external_event_id' => $externalId],
                    [
                        'organization_id' => $connection->organization_id,
                        'connection_id' => $connection->id,
                        'event_type' => 'instant_form_lead',
                        'payload' => $value,
                    ]
                );
                if ($event->wasRecentlyCreated) {
                    RetrieveMetaLead::dispatch($event->id);
                }
            }
        }

        return response()->json(['received' => true]);
    }

    public function whatsapp(Request $request): JsonResponse
    {
        $this->verifySignature($request, (string) config('services.meta.app_secret'));
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $phoneNumberId = (string) data_get($value, 'metadata.phone_number_id', '');
                $connection = IntegrationConnection::query()
                    ->where('provider', 'whatsapp')
                    ->where('external_account_id', $phoneNumberId)
                    ->where('status', 'active')
                    ->first();
                if (! $connection || ! data_get($connection->settings, 'eligibility_verified', false)) {
                    continue;
                }
                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $referral = (array) ($message['referral'] ?? []);
                    if ($referral === []) {
                        continue;
                    }
                    $data = [
                        'external_message_id' => $message['id'] ?? null,
                        'phone' => $message['from'] ?? null,
                        'name' => data_get($value, 'contacts.0.profile.name'),
                        'ad_id' => $referral['source_id'] ?? null,
                        'notes' => data_get($message, 'text.body'),
                        'captured_at' => isset($message['timestamp']) ? date(DATE_ATOM, (int) $message['timestamp']) : now()->toISOString(),
                    ];
                    $event = IntegrationEvent::query()->firstOrCreate(
                        ['provider' => 'whatsapp', 'external_event_id' => (string) ($message['id'] ?? Str::uuid())],
                        [
                            'organization_id' => $connection->organization_id,
                            'connection_id' => $connection->id,
                            'event_type' => 'click_to_whatsapp',
                            'payload' => $value,
                            'extracted_data' => $data,
                        ]
                    );
                    if ($event->wasRecentlyCreated) {
                        ProcessIntegrationEvent::dispatch($event->id);
                    }
                }
            }
        }

        return response()->json(['received' => true]);
    }

    public function inboundEmail(Request $request, EmailLeadParser $parser): JsonResponse
    {
        $data = $request->validate([
            'connection_id' => ['required', 'integer'], 'message_id' => ['required', 'string', 'max:500'],
            'from' => ['required', 'email'], 'to' => ['required', 'email'], 'subject' => ['nullable', 'string', 'max:1000'],
            'text' => ['nullable', 'string'], 'html' => ['nullable', 'string'], 'in_reply_to' => ['nullable', 'string', 'max:1000'],
        ]);
        $connection = IntegrationConnection::query()->whereKey($data['connection_id'])->where('status', 'active')->firstOrFail();
        $provided = (string) $request->header('X-Integration-Secret');
        abort_unless(hash_equals((string) data_get($connection->settings, 'inbound_secret'), $provided), 401);

        $parsed = $parser->parse((string) ($data['subject'] ?? ''), (string) ($data['text'] ?? $data['html'] ?? ''), empty($data['text']));
        $authorized = collect((array) data_get($connection->settings, 'authorized_senders', []))
            ->contains(fn ($email) => mb_strtolower((string) $email) === mb_strtolower($data['from']));
        $isReply = ! empty($data['in_reply_to']);
        $recipient = $isReply ? IntegrationCampaignRecipient::query()
            ->where('organization_id', $connection->organization_id)
            ->where('message_id', $data['in_reply_to'])->with('campaign')->first() : null;
        if ($recipient && $parsed['classification'] === 'unsubscribe') {
            $recipient->update(['status' => 'unsubscribed', 'unsubscribed_at' => now()]);
        } elseif ($recipient && $parsed['classification'] === 'bounce') {
            $recipient->update(['status' => 'bounced']);
        }
        $extracted = array_merge($parsed['fields'], [
            'classification' => $parsed['classification'], 'sender' => $data['from'],
            'receiving_alias' => $data['to'], 'mailbox_id' => (string) $connection->id,
            'external_message_id' => $data['message_id'], 'campaign_id' => $recipient?->campaign?->external_id,
            'lead_id' => $recipient?->lead_id,
        ]);
        $event = IntegrationEvent::query()->firstOrCreate(
            ['provider' => $connection->provider, 'external_event_id' => $data['message_id']],
            [
                'organization_id' => $connection->organization_id, 'connection_id' => $connection->id,
                'campaign_id' => $recipient?->campaign_id,
                'event_type' => $isReply ? 'email_reply' : 'forwarded_email',
                'status' => $authorized || $recipient ? 'pending' : 'review',
                'classification' => $parsed['classification'], 'extracted_data' => $extracted,
            ]
        );
        if ($event->wasRecentlyCreated) {
            if ($authorized || $recipient) {
                ProcessIntegrationEvent::dispatch($event->id);
            } else {
                IntegrationReviewItem::create([
                    'organization_id' => $connection->organization_id,
                    'integration_event_id' => $event->id,
                    'reason' => $isReply ? 'unmatched_email_campaign' : 'untrusted_sender',
                    'status' => 'pending',
                    'source_summary' => ['provider' => $connection->provider, 'sender' => $data['from'], 'subject' => $data['subject'] ?? null],
                    'extracted_data' => $extracted,
                ]);
            }
        }

        return response()->json(['received' => true], 202);
    }

    public function gmail(Request $request): JsonResponse
    {
        $expected = (string) config('services.google.pubsub_verification_token');
        abort_if($expected === '', 503, 'Gmail Pub/Sub verification is not configured.');
        abort_unless(hash_equals($expected, (string) $request->query('token')), 401);
        $encoded = (string) $request->input('message.data', '');
        $payload = json_decode((string) base64_decode(strtr($encoded, '-_', '+/')), true) ?: [];
        $email = mb_strtolower((string) ($payload['emailAddress'] ?? ''));
        $connection = IntegrationConnection::query()->where('provider', 'google')->where('status', 'active')
            ->where('account_email', $email)->first();
        if ($connection) {
            SyncMailbox::dispatch($connection->id);
        }

        return response()->json(['received' => true]);
    }

    public function microsoft(Request $request): JsonResponse|Response
    {
        if ($request->query('validationToken')) {
            return response((string) $request->query('validationToken'), 200)->header('Content-Type', 'text/plain');
        }
        foreach ((array) $request->input('value', []) as $notification) {
            $subscriptionId = (string) ($notification['subscriptionId'] ?? '');
            $connection = IntegrationConnection::query()->where('provider', 'microsoft')->where('status', 'active')->get()
                ->first(fn (IntegrationConnection $item) => hash_equals((string) data_get($item->settings, 'subscription_id'), $subscriptionId));
            if (! $connection || ! hash_equals((string) data_get($connection->settings, 'subscription_client_state'), (string) ($notification['clientState'] ?? ''))) {
                continue;
            }
            SyncMailbox::dispatch($connection->id);
        }

        return response()->json(['received' => true], 202);
    }

    private function verifySignature(Request $request, string $secret): void
    {
        abort_if($secret === '', 503, 'Provider webhook secret is not configured.');
        $signature = (string) $request->header('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless(hash_equals($expected, $signature), 401);
    }
}
