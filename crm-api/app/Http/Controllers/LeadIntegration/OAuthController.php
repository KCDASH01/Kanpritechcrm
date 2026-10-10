<?php

namespace App\Http\Controllers\LeadIntegration;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    public function authorizeProvider(Request $request, string $provider): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(in_array($provider, ['meta', 'google', 'microsoft'], true), 404);
        $this->assertConfigured($provider);

        $state = Crypt::encryptString(json_encode([
            'provider' => $provider, 'organization_id' => $request->user()->organization_id,
            'user_id' => $request->user()->id, 'nonce' => Str::uuid()->toString(), 'issued_at' => time(),
        ], JSON_THROW_ON_ERROR));

        return response()->json(['data' => ['authorization_url' => $this->authorizationUrl($provider, $state)]]);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, ['meta', 'google', 'microsoft'], true), 404);
        $request->validate(['code' => ['required', 'string'], 'state' => ['required', 'string']]);
        $state = json_decode(Crypt::decryptString($request->string('state')->toString()), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($state['provider'] ?? null) === $provider && time() - (int) ($state['issued_at'] ?? 0) <= 600, 401);
        $user = User::query()->whereKey($state['user_id'])->where('organization_id', $state['organization_id'])->firstOrFail();
        abort_unless($user->isAdmin(), 403);

        $provider === 'meta'
            ? $this->connectMeta($request->string('code')->toString(), $user)
            : $this->connectMailbox($provider, $request->string('code')->toString(), $user);

        return redirect(rtrim((string) config('services.lead_integrations.frontend_url'), '/').'/lead-integrations?connected='.$provider);
    }

    private function authorizationUrl(string $provider, string $state): string
    {
        if ($provider === 'meta') {
            $version = config('services.meta.graph_version');

            return 'https://www.facebook.com/'.$version.'/dialog/oauth?'.http_build_query([
                'client_id' => config('services.meta.app_id'), 'redirect_uri' => config('services.meta.redirect_uri'),
                'state' => $state, 'scope' => 'pages_show_list,pages_manage_metadata,leads_retrieval,ads_read',
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

    private function connectMeta(string $code, User $user): void
    {
        $version = config('services.meta.graph_version');
        $token = Http::asForm()->post("https://graph.facebook.com/{$version}/oauth/access_token", [
            'client_id' => config('services.meta.app_id'), 'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => config('services.meta.redirect_uri'), 'code' => $code,
        ])->throw()->json();
        $pages = Http::withToken($token['access_token'])->get("https://graph.facebook.com/{$version}/me/accounts", [
            'fields' => 'id,name,access_token,tasks',
        ])->throw()->json('data', []);
        foreach ($pages as $page) {
            IntegrationConnection::query()->updateOrCreate(
                ['organization_id' => $user->organization_id, 'provider' => 'meta', 'external_account_id' => (string) $page['id']],
                [
                    'connected_by' => $user->id, 'name' => (string) $page['name'], 'status' => 'active',
                    'access_token' => $page['access_token'], 'scopes' => ['pages_show_list', 'pages_manage_metadata', 'leads_retrieval', 'ads_read'],
                    'settings' => ['tasks' => $page['tasks'] ?? []], 'last_error' => null,
                ]
            );
        }
    }

    private function connectMailbox(string $provider, string $code, User $user): void
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
        IntegrationConnection::query()->updateOrCreate(
            ['organization_id' => $user->organization_id, 'provider' => $provider, 'external_account_id' => $email],
            [
                'connected_by' => $user->id, 'name' => $email, 'account_email' => $email, 'status' => 'active',
                'access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)), 'scopes' => $scopes,
                'settings' => ['history_id' => $profile['historyId'] ?? null], 'last_error' => null,
            ]
        );
    }

    private function assertConfigured(string $provider): void
    {
        $configured = match ($provider) {
            'meta' => config('services.meta.app_id') && config('services.meta.app_secret') && config('services.meta.graph_version') && config('services.meta.redirect_uri'),
            'google' => config('services.google.client_id') && config('services.google.client_secret') && config('services.google.redirect_uri'),
            'microsoft' => config('services.microsoft.client_id') && config('services.microsoft.client_secret') && config('services.microsoft.redirect_uri'),
        };
        abort_unless($configured, 503, ucfirst($provider).' OAuth is not configured on this server.');
    }
}
