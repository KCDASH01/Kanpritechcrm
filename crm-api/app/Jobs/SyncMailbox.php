<?php

namespace App\Jobs;

use App\Models\IntegrationCampaignRecipient;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEvent;
use App\Models\IntegrationReviewItem;
use App\Services\LeadIntegrations\EmailLeadParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SyncMailbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $connectionId) {}

    public function handle(EmailLeadParser $parser): void
    {
        $connection = IntegrationConnection::query()->findOrFail($this->connectionId);
        if ($connection->status !== 'active' || ! in_array($connection->provider, ['google', 'microsoft'], true)) {
            return;
        }
        $this->refreshTokenIfNeeded($connection);
        $messages = $connection->provider === 'google' ? $this->gmail($connection) : $this->microsoft($connection);
        foreach ($messages as $message) {
            $this->ingest($connection, $message, $parser);
        }
        $connection->update(['last_synced_at' => now(), 'last_error' => null]);
    }

    /** @return array<int, array<string, mixed>> */
    private function gmail(IntegrationConnection $connection): array
    {
        $list = Http::withToken($connection->access_token)->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', [
            'q' => 'newer_than:7d', 'maxResults' => 50,
        ])->throw()->json('messages', []);
        $result = [];
        foreach ($list as $row) {
            if (IntegrationEvent::query()->where('provider', 'google')->where('external_event_id', 'gmail:'.$row['id'])->exists()) {
                continue;
            }
            $message = Http::withToken($connection->access_token)->get('https://gmail.googleapis.com/gmail/v1/users/me/messages/'.$row['id'], ['format' => 'full'])->throw()->json();
            $headers = collect(data_get($message, 'payload.headers', []))->mapWithKeys(fn ($header) => [mb_strtolower($header['name']) => $header['value']])->all();
            $result[] = [
                'id' => 'gmail:'.$row['id'], 'message_id' => $headers['message-id'] ?? $row['id'],
                'thread_id' => $message['threadId'] ?? null, 'in_reply_to' => $headers['in-reply-to'] ?? null,
                'references' => $headers['references'] ?? null, 'from' => $this->emailAddress($headers['from'] ?? ''),
                'to' => $this->emailAddress($headers['to'] ?? ''), 'subject' => $headers['subject'] ?? '',
                'body' => $this->gmailBody((array) ($message['payload'] ?? [])), 'is_html' => str_contains((string) data_get($message, 'payload.mimeType'), 'html'),
            ];
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function microsoft(IntegrationConnection $connection): array
    {
        $since = ($connection->last_synced_at ?: now()->subDays(7))->copy()->subMinutes(5)->toIso8601String();
        $rows = Http::withToken($connection->access_token)->get('https://graph.microsoft.com/v1.0/me/messages', [
            '$filter' => "receivedDateTime ge {$since}", '$top' => 50,
            '$select' => 'id,internetMessageId,conversationId,from,toRecipients,subject,body,internetMessageHeaders,receivedDateTime',
        ])->throw()->json('value', []);
        $result = [];
        foreach ($rows as $row) {
            if (IntegrationEvent::query()->where('provider', 'microsoft')->where('external_event_id', 'outlook:'.$row['id'])->exists()) {
                continue;
            }
            $headers = collect($row['internetMessageHeaders'] ?? [])->mapWithKeys(fn ($header) => [mb_strtolower($header['name']) => $header['value']])->all();
            $result[] = [
                'id' => 'outlook:'.$row['id'], 'message_id' => $row['internetMessageId'] ?? $row['id'],
                'thread_id' => $row['conversationId'] ?? null, 'in_reply_to' => $headers['in-reply-to'] ?? null,
                'references' => $headers['references'] ?? null, 'from' => mb_strtolower((string) data_get($row, 'from.emailAddress.address')),
                'to' => mb_strtolower((string) data_get($row, 'toRecipients.0.emailAddress.address')),
                'subject' => $row['subject'] ?? '', 'body' => data_get($row, 'body.content', ''),
                'is_html' => data_get($row, 'body.contentType') === 'html',
            ];
        }

        return $result;
    }

    private function ingest(IntegrationConnection $connection, array $message, EmailLeadParser $parser): void
    {
        $parsed = $parser->parse((string) $message['subject'], (string) $message['body'], (bool) $message['is_html']);
        $authorized = collect((array) data_get($connection->settings, 'authorized_senders', []))->contains($message['from']);
        $recipient = $this->recipient($connection->organization_id, $message);
        if ($recipient && $parsed['classification'] === 'unsubscribe') {
            $recipient->update(['status' => 'unsubscribed', 'unsubscribed_at' => now()]);
        } elseif ($recipient && $parsed['classification'] === 'bounce') {
            $recipient->update(['status' => 'bounced']);
        }
        $eventType = $authorized && ! $recipient ? 'forwarded_email' : 'email_reply';
        $data = array_merge($parsed['fields'], [
            'classification' => $parsed['classification'], 'sender' => $message['from'], 'receiving_alias' => $message['to'],
            'mailbox_id' => (string) $connection->id, 'external_message_id' => $message['message_id'],
            'campaign_id' => $recipient?->campaign?->external_id,
        ]);
        if ($recipient?->lead_id) {
            $data['lead_id'] = $recipient->lead_id;
        }
        $event = IntegrationEvent::query()->firstOrCreate(
            ['provider' => $connection->provider, 'external_event_id' => $message['id']],
            [
                'organization_id' => $connection->organization_id, 'connection_id' => $connection->id,
                'campaign_id' => $recipient?->campaign_id, 'event_type' => $eventType,
                'classification' => $parsed['classification'], 'extracted_data' => $data,
            ]
        );
        if (! $event->wasRecentlyCreated) {
            return;
        }
        if ($eventType === 'email_reply' && ! $recipient) {
            $event->update(['status' => 'review', 'error_message' => 'unmatched_email_campaign', 'processed_at' => now()]);
            IntegrationReviewItem::create([
                'organization_id' => $connection->organization_id, 'integration_event_id' => $event->id,
                'reason' => 'unmatched_email_campaign', 'status' => 'pending',
                'source_summary' => ['provider' => $connection->provider, 'sender' => $message['from'], 'subject' => $message['subject']],
                'extracted_data' => $data,
            ]);

            return;
        }
        ProcessIntegrationEvent::dispatch($event->id);
    }

    private function recipient(int $organizationId, array $message): ?IntegrationCampaignRecipient
    {
        $references = trim(($message['in_reply_to'] ?? '').' '.($message['references'] ?? ''));
        if (! empty($message['thread_id'])) {
            $threadMatch = IntegrationCampaignRecipient::query()->where('organization_id', $organizationId)
                ->where('thread_id', $message['thread_id'])->with('campaign')->first();
            if ($threadMatch) {
                return $threadMatch;
            }
        }
        if ($references === '') {
            return null;
        }

        return IntegrationCampaignRecipient::query()->where('organization_id', $organizationId)
            ->whereNotNull('message_id')->with('campaign')->get()
            ->first(fn (IntegrationCampaignRecipient $recipient) => str_contains($references, (string) $recipient->message_id));
    }

    private function refreshTokenIfNeeded(IntegrationConnection $connection): void
    {
        if (! $connection->token_expires_at || $connection->token_expires_at->isAfter(now()->addMinutes(5))) {
            return;
        }
        if (! $connection->refresh_token) {
            throw new RuntimeException('Mailbox authorization expired and no refresh token is available.');
        }
        if ($connection->provider === 'google') {
            $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $connection->refresh_token, 'grant_type' => 'refresh_token',
            ])->throw()->json();
        } else {
            $tenant = config('services.microsoft.tenant');
            $token = Http::asForm()->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'client_id' => config('services.microsoft.client_id'), 'client_secret' => config('services.microsoft.client_secret'),
                'refresh_token' => $connection->refresh_token, 'grant_type' => 'refresh_token', 'scope' => 'openid email offline_access User.Read Mail.Read',
            ])->throw()->json();
        }
        $connection->update([
            'access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
        ]);
        $connection->refresh();
    }

    private function gmailBody(array $part): string
    {
        $data = data_get($part, 'body.data');
        if ($data) {
            return (string) base64_decode(strtr($data, '-_', '+/'));
        }
        foreach ((array) ($part['parts'] ?? []) as $child) {
            if (in_array($child['mimeType'] ?? '', ['text/plain', 'text/html'], true)) {
                $body = $this->gmailBody((array) $child);
                if ($body !== '') {
                    return $body;
                }
            }
        }

        return '';
    }

    private function emailAddress(string $value): string
    {
        return preg_match('/<([^>]+)>/', $value, $match) ? mb_strtolower($match[1]) : mb_strtolower(trim($value));
    }

    public function failed(\Throwable $exception): void
    {
        $message = mb_substr($exception->getMessage(), 0, 2000);
        IntegrationConnection::query()->whereKey($this->connectionId)->update([
            'status' => str_contains($message, '401') ? 'expired' : 'active',
            'last_error' => $message,
        ]);
    }
}
