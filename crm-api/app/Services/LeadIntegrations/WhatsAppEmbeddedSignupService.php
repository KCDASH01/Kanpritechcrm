<?php

namespace App\Services\LeadIntegrations;

use App\Models\IntegrationAsset;
use App\Models\IntegrationConnection;
use App\Models\IntegrationConnectionAudit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class WhatsAppEmbeddedSignupService
{
    private const SESSION_TTL_MINUTES = 10;

    public function start(User $user): array
    {
        $this->assertConfigured();

        $sessionId = (string) Str::uuid();
        Cache::put($this->cacheKey($sessionId), [
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'issued_at' => now()->timestamp,
        ], now()->addMinutes(self::SESSION_TTL_MINUTES));

        return [
            'session_id' => $sessionId,
            'app_id' => (string) config('services.meta.app_id'),
            'config_id' => (string) config('services.meta.whatsapp_config_id'),
            'feature_type' => (string) config('services.meta.whatsapp_feature_type'),
            'signup_version' => (string) config('services.meta.whatsapp_signup_version'),
            'session_info_version' => (string) config('services.meta.whatsapp_session_info_version'),
            'expires_in' => self::SESSION_TTL_MINUTES * 60,
        ];
    }

    public function complete(User $user, array $data): IntegrationConnection
    {
        $this->assertConfigured();

        $session = Cache::get($this->cacheKey($data['session_id']));
        if (! is_array($session)
            || (int) ($session['organization_id'] ?? 0) !== (int) $user->organization_id
            || (int) ($session['user_id'] ?? 0) !== (int) $user->id) {
            throw ValidationException::withMessages([
                'session_id' => 'This signup session is invalid, expired, or has already been used. Start again.',
            ]);
        }
        Cache::forget($this->cacheKey($data['session_id']));
        $this->guardTenantOwnership($user, (string) $data['waba_id'], (string) $data['phone_number_id']);

        try {
            $credentials = $this->exchangeCode((string) $data['code']);
            $token = $credentials['token'];
            $waba = $this->fetchWaba((string) $data['waba_id'], $token);
            $phone = $this->fetchAuthorizedPhone((string) $data['waba_id'], (string) $data['phone_number_id'], $token);
            $this->subscribeWaba((string) $data['waba_id'], $token);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'connection' => 'Meta could not verify or subscribe this WhatsApp Business account. Confirm its permissions and try again.',
            ]);
        }

        return DB::transaction(function () use ($user, $data, $credentials, $token, $waba, $phone): IntegrationConnection {
            $existing = IntegrationConnection::withTrashed()
                ->where('organization_id', $user->organization_id)
                ->where('provider', 'whatsapp')
                ->where('external_account_id', (string) $data['waba_id'])
                ->first();

            if ($existing && ! $existing->trashed() && $existing->status !== 'disconnected'
                && $existing->assets()->where('asset_type', 'phone_number')->where('external_id', (string) $data['phone_number_id'])->where('is_selected', true)->exists()) {
                abort(409, 'This WhatsApp Business number is already connected to this organization.');
            }

            $connection = $existing ?: new IntegrationConnection;
            if ($connection->trashed()) {
                $connection->restore();
            }
            $connection->fill([
                'organization_id' => $user->organization_id,
                'connected_by' => $user->id,
                'provider' => 'whatsapp',
                'name' => (string) ($waba['name'] ?? $phone['verified_name'] ?? 'WhatsApp Business'),
                'status' => 'active',
                'external_account_id' => (string) $data['waba_id'],
                'access_token' => $token,
                'token_expires_at' => $credentials['expires_at'],
                'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'],
                'settings' => [
                    'eligibility_verified' => true,
                    'embedded_signup_completed' => true,
                    'coexistence_mode_requested' => true,
                    'coexistence_status' => 'authorized_by_meta_embedded_signup',
                    'waba_id' => (string) $data['waba_id'],
                    'phone_number_id' => (string) $data['phone_number_id'],
                    'feature_type' => (string) config('services.meta.whatsapp_feature_type'),
                    'session_info_version' => (string) config('services.meta.whatsapp_session_info_version'),
                ],
                'webhook_status' => 'subscribed',
                'health_checked_at' => now(),
                'last_error' => null,
            ])->save();

            $connection->assets()->where('provider', 'whatsapp')->update(['is_selected' => false]);
            $this->upsertAsset($connection, 'whatsapp_business_account', (string) $data['waba_id'], null,
                (string) ($waba['name'] ?? 'WhatsApp Business Account'), $waba, $token);
            $this->upsertAsset($connection, 'phone_number', (string) $data['phone_number_id'], (string) $data['waba_id'],
                (string) ($phone['display_phone_number'] ?? $phone['verified_name'] ?? $data['phone_number_id']), $phone, $token);

            IntegrationConnectionAudit::create([
                'organization_id' => $user->organization_id,
                'connection_id' => $connection->id,
                'actor_id' => $user->id,
                'action' => 'whatsapp_embedded_signup',
                'status' => 'completed',
                'details' => [
                    'waba_id' => (string) $data['waba_id'],
                    'phone_number_id' => (string) $data['phone_number_id'],
                    'webhook_subscribed' => true,
                ],
            ]);

            return $connection->load('assets');
        });
    }

    private function exchangeCode(string $code): array
    {
        $response = Http::timeout(15)->get($this->graphUrl('/oauth/access_token'), [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'code' => $code,
        ])->throw()->json();
        $token = (string) ($response['access_token'] ?? '');
        if ($token === '') {
            throw ValidationException::withMessages(['code' => 'Meta did not return an access token. Start signup again.']);
        }

        return [
            'token' => $token,
            'expires_at' => isset($response['expires_in']) && (int) $response['expires_in'] > 0
                ? now()->addSeconds((int) $response['expires_in'])
                : null,
        ];
    }

    private function fetchWaba(string $wabaId, string $token): array
    {
        $response = Http::timeout(15)->withToken($token)->get($this->graphUrl('/'.$wabaId), [
            'fields' => 'id,name,account_review_status,business_verification_status',
        ])->throw()->json();
        if ((string) ($response['id'] ?? '') !== $wabaId) {
            throw ValidationException::withMessages(['waba_id' => 'The selected WhatsApp Business Account could not be verified.']);
        }

        return $response;
    }

    private function fetchAuthorizedPhone(string $wabaId, string $phoneId, string $token): array
    {
        $response = Http::timeout(15)->withToken($token)->get($this->graphUrl('/'.$wabaId.'/phone_numbers'), [
            'fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status,platform_type',
        ])->throw()->json();
        $phone = collect((array) ($response['data'] ?? []))->first(fn ($item) => (string) ($item['id'] ?? '') === $phoneId);
        if (! is_array($phone)) {
            throw ValidationException::withMessages(['phone_number_id' => 'The selected phone number does not belong to the authorized WhatsApp Business Account.']);
        }

        return $phone;
    }

    private function subscribeWaba(string $wabaId, string $token): void
    {
        $response = Http::timeout(15)->withToken($token)->post($this->graphUrl('/'.$wabaId.'/subscribed_apps'));
        if (! $response->successful() || $response->json('success') !== true) {
            throw ValidationException::withMessages(['connection' => 'Meta authorization succeeded, but webhook subscription failed. No active connection was created.']);
        }
    }

    private function guardTenantOwnership(User $user, string $wabaId, string $phoneId): void
    {
        $claimedConnection = IntegrationConnection::query()->where('provider', 'whatsapp')
            ->where('organization_id', '!=', $user->organization_id)
            ->where('status', '!=', 'disconnected')
            ->whereIn('external_account_id', [$wabaId, $phoneId])
            ->exists();
        $claimedAsset = IntegrationAsset::query()->where('provider', 'whatsapp')
            ->whereIn('external_id', [$wabaId, $phoneId])
            ->whereHas('connection', fn ($query) => $query->where('organization_id', '!=', $user->organization_id)->where('status', '!=', 'disconnected'))
            ->exists();
        abort_if($claimedConnection || $claimedAsset, 409, 'This WhatsApp Business asset is already connected to another organization.');
    }

    private function upsertAsset(IntegrationConnection $connection, string $type, string $externalId, ?string $parentId, string $name, array $metadata, string $token): void
    {
        IntegrationAsset::updateOrCreate([
            'connection_id' => $connection->id,
            'provider' => 'whatsapp',
            'asset_type' => $type,
            'external_id' => $externalId,
        ], [
            'organization_id' => $connection->organization_id,
            'parent_external_id' => $parentId,
            'name' => $name,
            'status' => 'active',
            'is_selected' => true,
            'access_token' => $token,
            'metadata' => $metadata,
            'last_verified_at' => now(),
        ]);
    }

    private function assertConfigured(): void
    {
        abort_unless(config('services.meta.app_id') && config('services.meta.app_secret')
            && config('services.meta.graph_version') && config('services.meta.whatsapp_config_id'),
            503, 'WhatsApp Embedded Signup is not configured.');
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.trim((string) config('services.meta.graph_version'), '/').$path;
    }

    private function cacheKey(string $sessionId): string
    {
        return 'whatsapp-embedded-signup:'.$sessionId;
    }
}
