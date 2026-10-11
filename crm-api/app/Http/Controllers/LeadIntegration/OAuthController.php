<?php

namespace App\Http\Controllers\LeadIntegration;

use App\Http\Controllers\Controller;
use App\Models\IntegrationAsset;
use App\Models\IntegrationConnection;
use App\Models\IntegrationConnectionAudit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    public function authorizeProvider(Request $request, string $provider): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(in_array($provider, ['meta', 'whatsapp', 'google', 'microsoft'], true), 404);
        $this->assertConfigured($provider);

        $nonce = Str::uuid()->toString();
        Cache::put('lead-integration-oauth:'.$provider.':'.$nonce, true, now()->addMinutes(10));
        $state = Crypt::encryptString(json_encode([
            'provider' => $provider, 'organization_id' => $request->user()->organization_id,
            'user_id' => $request->user()->id, 'nonce' => $nonce, 'issued_at' => time(),
        ], JSON_THROW_ON_ERROR));

        return response()->json(['data' => ['authorization_url' => $this->authorizationUrl($provider, $state)]]);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, ['meta', 'whatsapp', 'google', 'microsoft'], true), 404);
        $request->validate(['code' => ['required', 'string'], 'state' => ['required', 'string']]);
        $state = json_decode(Crypt::decryptString($request->string('state')->toString()), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($state['provider'] ?? null) === $provider && time() - (int) ($state['issued_at'] ?? 0) <= 600, 401);
        abort_unless(Cache::pull('lead-integration-oauth:'.$provider.':'.(string) ($state['nonce'] ?? '')) === true, 401, 'OAuth state expired or was already used.');
        $user = User::query()->whereKey($state['user_id'])->where('organization_id', $state['organization_id'])->firstOrFail();
        abort_unless($user->isAdmin(), 403);

        $connection = match ($provider) {
            'meta' => $this->connectMeta($request->string('code')->toString(), $user),
            'whatsapp' => $this->connectWhatsApp($request->string('code')->toString(), $user),
            default => $this->connectMailbox($provider, $request->string('code')->toString(), $user),
        };

        return redirect(rtrim((string) config('services.lead_integrations.frontend_url'), '/').'/lead-integrations?connected='.$provider.'&connection_id='.$connection->id.'&step=assets');
    }

    private function authorizationUrl(string $provider, string $state): string
    {
        if (in_array($provider, ['meta', 'whatsapp'], true)) {
            $version = config('services.meta.graph_version');

            $scopes = $provider === 'whatsapp'
                ? 'business_management,whatsapp_business_management,whatsapp_business_messaging'
                : 'business_management,pages_show_list,pages_manage_metadata,leads_retrieval,ads_read';

            return 'https://www.facebook.com/'.$version.'/dialog/oauth?'.http_build_query([
                'client_id' => config('services.meta.app_id'),
                'redirect_uri' => $provider === 'whatsapp' ? config('services.meta.whatsapp_redirect_uri') : config('services.meta.redirect_uri'),
                'state' => $state, 'scope' => $scopes,
                'config_id' => $provider === 'whatsapp' ? config('services.meta.whatsapp_config_id') : null,
            ]);
        }
        if ($provider === 'google') {
            return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
                'client_id' => config('services.google.client_id'), 'redirect_uri' => config('services.google.redirect_uri'),
                'response_type' => 'code', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
                'scope' => 'openid email https://www.googleapis.com/auth/gmail.readonly',
            ]);
        }
        $tenant = config('services.microsoft.tenant');

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize?".http_build_query([
            'client_id' => config('services.microsoft.client_id'), 'redirect_uri' => config('services.microsoft.redirect_uri'),
            'response_type' => 'code', 'response_mode' => 'query', 'state' => $state,
            'scope' => 'openid email offline_access User.Read Mail.Read',
        ]);
    }

    private function connectMeta(string $code, User $user): IntegrationConnection
    {
        $version = config('services.meta.graph_version');
        $token = Http::asForm()->post("https://graph.facebook.com/{$version}/oauth/access_token", [
            'client_id' => config('services.meta.app_id'), 'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => config('services.meta.redirect_uri'), 'code' => $code,
        ])->throw()->json();
        $identity = Http::withToken($token['access_token'])->get("https://graph.facebook.com/{$version}/me", ['fields' => 'id,name'])->throw()->json();
        $connection = IntegrationConnection::query()->updateOrCreate(
            ['organization_id' => $user->organization_id, 'provider' => 'meta', 'external_account_id' => (string) $identity['id']],
            [
                'connected_by' => $user->id, 'name' => (string) ($identity['name'] ?? 'Meta Business'),
                'status' => 'setup_required', 'access_token' => $token['access_token'],
                'token_expires_at' => isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : null,
                'scopes' => ['business_management', 'pages_show_list', 'pages_manage_metadata', 'leads_retrieval', 'ads_read'],
                'settings' => ['authorized_identity' => $identity], 'last_error' => null, 'webhook_status' => 'pending',
            ]
        );
        $pages = Http::withToken($token['access_token'])->get("https://graph.facebook.com/{$version}/me/accounts", [
            'fields' => 'id,name,access_token,tasks',
        ])->throw()->json('data', []);
        foreach ($pages as $page) {
            $pageAsset = IntegrationAsset::query()->updateOrCreate(
                ['connection_id' => $connection->id, 'asset_type' => 'page', 'external_id' => (string) $page['id']],
                [
                    'organization_id' => $user->organization_id, 'provider' => 'meta', 'name' => (string) $page['name'],
                    'status' => 'available', 'access_token' => $page['access_token'] ?? null,
                    'capabilities' => $page['tasks'] ?? [], 'last_verified_at' => now(),
                ]
            );
            $forms = Http::withToken((string) ($page['access_token'] ?? $token['access_token']))
                ->get("https://graph.facebook.com/{$version}/{$page['id']}/leadgen_forms", ['fields' => 'id,name,status'])->json('data', []);
            foreach ($forms as $form) {
                IntegrationAsset::query()->updateOrCreate(
                    ['connection_id' => $connection->id, 'asset_type' => 'form', 'external_id' => (string) $form['id']],
                    [
                        'organization_id' => $user->organization_id, 'provider' => 'meta',
                        'parent_external_id' => $pageAsset->external_id, 'name' => (string) ($form['name'] ?? $form['id']),
                        'status' => strtolower((string) ($form['status'] ?? 'available')), 'metadata' => $form,
                        'last_verified_at' => now(),
                    ]
                );
            }
        }
        $this->syncMetaCollection($connection, $token['access_token'], 'business', "https://graph.facebook.com/{$version}/me/businesses", 'id,name');
        $this->syncMetaCollection($connection, $token['access_token'], 'ad_account', "https://graph.facebook.com/{$version}/me/adaccounts", 'id,name,account_status');
        $this->audit($connection, $user, 'authorize', 'success', ['assets_discovered' => $connection->assets()->count()]);

        return $connection;
    }

    private function connectWhatsApp(string $code, User $user): IntegrationConnection
    {
        $version = config('services.meta.graph_version');
        $token = Http::asForm()->post("https://graph.facebook.com/{$version}/oauth/access_token", [
            'client_id' => config('services.meta.app_id'), 'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => config('services.meta.whatsapp_redirect_uri'), 'code' => $code,
        ])->throw()->json();
        $identity = Http::withToken($token['access_token'])->get("https://graph.facebook.com/{$version}/me", ['fields' => 'id,name'])->throw()->json();
        $connection = IntegrationConnection::query()->updateOrCreate(
            ['organization_id' => $user->organization_id, 'provider' => 'whatsapp', 'external_account_id' => (string) $identity['id']],
            [
                'connected_by' => $user->id, 'name' => (string) ($identity['name'] ?? 'WhatsApp Business'),
                'status' => 'setup_required', 'access_token' => $token['access_token'],
                'scopes' => ['business_management', 'whatsapp_business_management', 'whatsapp_business_messaging'],
                'settings' => ['embedded_signup' => true, 'eligibility_verified' => false], 'webhook_status' => 'pending', 'last_error' => null,
            ]
        );
        $businesses = Http::withToken($token['access_token'])->get("https://graph.facebook.com/{$version}/me/businesses", ['fields' => 'id,name'])->json('data', []);
        foreach ($businesses as $business) {
            IntegrationAsset::query()->updateOrCreate(
                ['connection_id' => $connection->id, 'asset_type' => 'business', 'external_id' => (string) $business['id']],
                ['organization_id' => $user->organization_id, 'provider' => 'whatsapp', 'name' => (string) $business['name'], 'last_verified_at' => now()]
            );
            $wabas = Http::withToken($token['access_token'])->get("https://graph.facebook.com/{$version}/{$business['id']}/owned_whatsapp_business_accounts", ['fields' => 'id,name'])->json('data', []);
            foreach ($wabas as $waba) {
                IntegrationAsset::query()->updateOrCreate(
                    ['connection_id' => $connection->id, 'asset_type' => 'whatsapp_business_account', 'external_id' => (string) $waba['id']],
                    ['organization_id' => $user->organization_id, 'provider' => 'whatsapp', 'parent_external_id' => (string) $business['id'], 'name' => (string) ($waba['name'] ?? $waba['id']), 'last_verified_at' => now()]
                );
                $numbers = Http::withToken($token['access_token'])->get("https://graph.facebook.com/{$version}/{$waba['id']}/phone_numbers", ['fields' => 'id,display_phone_number,verified_name,quality_rating'])->json('data', []);
                foreach ($numbers as $number) {
                    IntegrationAsset::query()->updateOrCreate(
                        ['connection_id' => $connection->id, 'asset_type' => 'phone_number', 'external_id' => (string) $number['id']],
                        ['organization_id' => $user->organization_id, 'provider' => 'whatsapp', 'parent_external_id' => (string) $waba['id'], 'name' => (string) ($number['display_phone_number'] ?? $number['id']), 'metadata' => $number, 'last_verified_at' => now()]
                    );
                }
            }
        }
        $this->audit($connection, $user, 'authorize', 'success', ['assets_discovered' => $connection->assets()->count()]);

        return $connection;
    }

    private function connectMailbox(string $provider, string $code, User $user): IntegrationConnection
    {
        if ($provider === 'google') {
            $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => config('services.google.redirect_uri'), 'grant_type' => 'authorization_code', 'code' => $code,
            ])->throw()->json();
            $profile = Http::withToken($token['access_token'])->get('https://gmail.googleapis.com/gmail/v1/users/me/profile')->throw()->json();
            $email = mb_strtolower((string) $profile['emailAddress']);
            $scopes = ['https://www.googleapis.com/auth/gmail.readonly'];
        } else {
            $tenant = config('services.microsoft.tenant');
            $token = Http::asForm()->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'client_id' => config('services.microsoft.client_id'), 'client_secret' => config('services.microsoft.client_secret'),
                'redirect_uri' => config('services.microsoft.redirect_uri'), 'grant_type' => 'authorization_code', 'code' => $code,
                'scope' => 'openid email offline_access User.Read Mail.Read',
            ])->throw()->json();
            $profile = Http::withToken($token['access_token'])->get('https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName')->throw()->json();
            $email = mb_strtolower((string) ($profile['mail'] ?? $profile['userPrincipalName']));
            $scopes = ['Mail.Read'];
        }
        $connection = IntegrationConnection::query()->updateOrCreate(
            ['organization_id' => $user->organization_id, 'provider' => $provider, 'external_account_id' => $email],
            [
                'connected_by' => $user->id, 'name' => $email, 'account_email' => $email, 'status' => 'active',
                'access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)), 'scopes' => $scopes,
                'settings' => ['history_id' => $profile['historyId'] ?? null], 'last_error' => null,
            ]
        );
        IntegrationAsset::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'asset_type' => 'mailbox', 'external_id' => $email],
            ['organization_id' => $user->organization_id, 'provider' => $provider, 'name' => $email, 'status' => 'active', 'is_selected' => true, 'last_verified_at' => now()]
        );
        $this->audit($connection, $user, 'authorize', 'success', ['mailbox' => $email]);

        return $connection;
    }

    private function assertConfigured(string $provider): void
    {
        $configured = match ($provider) {
            'meta' => config('services.meta.app_id') && config('services.meta.app_secret') && config('services.meta.graph_version') && config('services.meta.redirect_uri'),
            'whatsapp' => config('services.meta.app_id') && config('services.meta.app_secret') && config('services.meta.graph_version') && config('services.meta.whatsapp_redirect_uri') && config('services.meta.whatsapp_config_id'),
            'google' => config('services.google.client_id') && config('services.google.client_secret') && config('services.google.redirect_uri'),
            'microsoft' => config('services.microsoft.client_id') && config('services.microsoft.client_secret') && config('services.microsoft.redirect_uri'),
        };
        abort_unless($configured, 503, ucfirst($provider).' OAuth is not configured on this server.');
    }

    private function syncMetaCollection(IntegrationConnection $connection, string $token, string $type, string $url, string $fields): void
    {
        $response = Http::withToken($token)->get($url, ['fields' => $fields]);
        if (! $response->successful()) {
            return;
        }
        foreach ($response->json('data', []) as $asset) {
            IntegrationAsset::query()->updateOrCreate(
                ['connection_id' => $connection->id, 'asset_type' => $type, 'external_id' => (string) $asset['id']],
                ['organization_id' => $connection->organization_id, 'provider' => $connection->provider, 'name' => (string) ($asset['name'] ?? $asset['id']), 'metadata' => $asset, 'last_verified_at' => now()]
            );
        }
    }

    private function audit(IntegrationConnection $connection, User $user, string $action, string $status, array $details = []): void
    {
        IntegrationConnectionAudit::create([
            'organization_id' => $connection->organization_id, 'connection_id' => $connection->id,
            'actor_id' => $user->id, 'action' => $action, 'status' => $status, 'details' => $details,
        ]);
    }
}
