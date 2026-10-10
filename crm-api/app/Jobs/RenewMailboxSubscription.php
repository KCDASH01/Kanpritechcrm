<?php

namespace App\Jobs;

use App\Models\IntegrationConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class RenewMailboxSubscription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $connectionId) {}

    public function handle(): void
    {
        $connection = IntegrationConnection::query()->findOrFail($this->connectionId);
        if ($connection->status !== 'active' || ! $connection->access_token) {
            return;
        }
        $settings = $connection->settings ?? [];
        $expiresAt = data_get($settings, 'subscription_expires_at');
        if ($expiresAt && now()->addDay()->isBefore($expiresAt)) {
            return;
        }

        if ($connection->provider === 'google' && config('services.google.pubsub_topic')) {
            $watch = Http::withToken($connection->access_token)->post('https://gmail.googleapis.com/gmail/v1/users/me/watch', [
                'topicName' => config('services.google.pubsub_topic'), 'labelIds' => ['INBOX'], 'labelFilterBehavior' => 'include',
            ])->throw()->json();
            $settings['history_id'] = $watch['historyId'] ?? data_get($settings, 'history_id');
            $settings['subscription_expires_at'] = isset($watch['expiration'])
                ? now()->setTimestamp((int) floor(((int) $watch['expiration']) / 1000))->toIso8601String() : null;
        } elseif ($connection->provider === 'microsoft' && config('services.microsoft.notification_url')) {
            $clientState = data_get($settings, 'subscription_client_state') ?: bin2hex(random_bytes(24));
            $payload = [
                'changeType' => 'created', 'notificationUrl' => config('services.microsoft.notification_url'),
                'lifecycleNotificationUrl' => config('services.microsoft.notification_url'),
                'resource' => 'me/mailFolders/inbox/messages', 'expirationDateTime' => now()->addDays(2)->toIso8601String(),
                'clientState' => $clientState,
            ];
            $subscriptionId = data_get($settings, 'subscription_id');
            $response = $subscriptionId
                ? Http::withToken($connection->access_token)->patch('https://graph.microsoft.com/v1.0/subscriptions/'.$subscriptionId, ['expirationDateTime' => $payload['expirationDateTime']])
                : Http::withToken($connection->access_token)->post('https://graph.microsoft.com/v1.0/subscriptions', $payload);
            $subscription = $response->throw()->json();
            $settings['subscription_id'] = $subscription['id'] ?? $subscriptionId;
            $settings['subscription_client_state'] = $clientState;
            $settings['subscription_expires_at'] = $subscription['expirationDateTime'] ?? $payload['expirationDateTime'];
        } else {
            return;
        }

        $connection->update(['settings' => $settings, 'last_error' => null]);
    }

    public function failed(\Throwable $exception): void
    {
        IntegrationConnection::query()->whereKey($this->connectionId)->update([
            'last_error' => mb_substr('Subscription renewal: '.$exception->getMessage(), 0, 2000),
        ]);
    }
}
