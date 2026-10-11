<?php

namespace Tests\Feature;

use App\Http\Middleware\BusinessOnly;
use App\Http\Middleware\CheckSubscription;
use App\Jobs\RenewMailboxSubscription;
use App\Jobs\SyncMailbox;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailAccountOAuthTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([CheckSubscription::class, BusinessOnly::class]);
        Queue::fake();
        Cache::flush();

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('employee');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('connected_by')->nullable();
            $table->string('provider', 32);
            $table->string('name');
            $table->string('status', 32)->default('not_connected');
            $table->string('external_account_id')->nullable();
            $table->string('account_email')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->string('webhook_status', 32)->default('pending');
            $table->text('sync_cursor')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'provider', 'external_account_id']);
        });
        Schema::create('integration_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('connection_id');
            $table->string('provider', 32);
            $table->string('asset_type', 48);
            $table->string('external_id');
            $table->string('parent_external_id')->nullable();
            $table->string('name');
            $table->string('status', 32)->default('available');
            $table->boolean('is_selected')->default(false);
            $table->text('access_token')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('integration_connection_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 64);
            $table->string('status', 32);
            $table->json('details')->nullable();
            $table->timestamps();
        });

        $organization = Organization::create(['name' => 'Acme', 'slug' => 'acme', 'is_active' => true]);
        $this->owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'email' => 'owner@example.com', 'role' => 'owner', 'is_active' => true]);
        Sanctum::actingAs($this->owner);
        config(['services.lead_integrations.frontend_url' => 'https://crm.example.com']);
    }

    public function test_google_authorization_uses_pkce_and_connects_mailbox(): void
    {
        $this->configureGoogle();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600, 'scope' => 'openid email https://www.googleapis.com/auth/gmail.readonly']),
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'Owner@Example.com', 'historyId' => '123']),
        ]);

        $authorization = $this->getJson('/api/lead-integrations/oauth/google')->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($authorization, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);

        $this->get('/api/lead-integrations/oauth/google/callback?'.http_build_query(['code' => 'code', 'state' => $query['state']]))
            ->assertRedirectContains('connected=google');
        $this->assertDatabaseHas('integration_connections', ['organization_id' => $this->owner->organization_id, 'provider' => 'google', 'account_email' => 'owner@example.com', 'status' => 'active']);
        $this->assertDatabaseHas('integration_assets', ['provider' => 'google', 'external_id' => 'owner@example.com', 'is_selected' => true]);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token' && filled($request['code_verifier']));

        $connection = IntegrationConnection::query()->where('provider', 'google')->firstOrFail();
        $this->deleteJson("/api/lead-integrations/connections/{$connection->id}")->assertOk();
        $reconnectUrl = $this->getJson('/api/lead-integrations/oauth/google')->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($reconnectUrl, PHP_URL_QUERY), $reconnectQuery);
        $this->get('/api/lead-integrations/oauth/google/callback?'.http_build_query(['code' => 'new-code', 'state' => $reconnectQuery['state']]))
            ->assertRedirectContains('connected=google');
        $this->assertSame(1, IntegrationConnection::query()->where('provider', 'google')->count());
        $this->assertDatabaseHas('integration_connections', ['id' => $connection->id, 'status' => 'active']);
    }

    public function test_microsoft_authorization_connects_mailbox(): void
    {
        $this->configureMicrosoft();
        Http::fake([
            'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600, 'scope' => 'openid email offline_access User.Read Mail.Read']),
            'https://graph.microsoft.com/v1.0/me*' => Http::response(['id' => 'abc', 'displayName' => 'Owner', 'mail' => 'owner@microsoft.example']),
        ]);

        $authorization = $this->getJson('/api/lead-integrations/oauth/microsoft')->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($authorization, PHP_URL_QUERY), $query);
        $this->get('/api/lead-integrations/oauth/microsoft/callback?'.http_build_query(['code' => 'code', 'state' => $query['state']]))
            ->assertRedirectContains('connected=microsoft');
        $this->assertDatabaseHas('integration_connections', ['provider' => 'microsoft', 'account_email' => 'owner@microsoft.example']);
    }

    public function test_cancellation_invalid_state_and_callback_replay_are_safe(): void
    {
        $this->configureGoogle();
        $authorization = $this->getJson('/api/lead-integrations/oauth/google')->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($authorization, PHP_URL_QUERY), $query);

        $this->get('/api/lead-integrations/oauth/google/callback?'.http_build_query(['error' => 'access_denied', 'state' => $query['state']]))
            ->assertRedirectContains('oauth_error=access_denied');
        $this->get('/api/lead-integrations/oauth/google/callback?'.http_build_query(['code' => 'replay', 'state' => $query['state']]))
            ->assertRedirectContains('oauth_error=expired_state');
        $this->get('/api/lead-integrations/oauth/google/callback?state=invalid')
            ->assertRedirectContains('oauth_error=invalid_state');
    }

    public function test_expired_state_and_missing_provider_configuration_return_friendly_results(): void
    {
        $this->configureGoogle();
        $state = Crypt::encryptString(json_encode(['provider' => 'google', 'organization_id' => $this->owner->organization_id, 'user_id' => $this->owner->id, 'nonce' => 'expired', 'issued_at' => time() - 601], JSON_THROW_ON_ERROR));
        Cache::put('lead-integration-oauth:google:expired', ['code_verifier' => 'verifier'], now()->addMinute());
        $this->get('/api/lead-integrations/oauth/google/callback?'.http_build_query(['code' => 'code', 'state' => $state]))
            ->assertRedirectContains('oauth_error=invalid_state');

        config(['services.google.client_id' => null, 'services.google.client_secret' => null, 'services.google.redirect_uri' => null]);
        $this->getJson('/api/lead-integrations/oauth/google')->assertStatus(503)
            ->assertJsonPath('message', 'Google connection is temporarily unavailable. Please contact your CRM administrator.');
    }

    public function test_mailbox_settings_are_scoped_and_queue_sync(): void
    {
        $connection = IntegrationConnection::create(['organization_id' => $this->owner->organization_id, 'connected_by' => $this->owner->id, 'provider' => 'google', 'name' => 'Inbox', 'status' => 'active', 'external_account_id' => 'owner@example.com', 'account_email' => 'owner@example.com', 'access_token' => 'token']);
        $this->putJson("/api/lead-integrations/connections/{$connection->id}", [
            'name' => 'Sales Inbox', 'department_id' => null, 'default_owner_id' => $this->owner->id,
            'auto_assign' => true, 'capture_new_enquiries' => true, 'capture_campaign_replies' => true,
            'review_unrecognized' => true, 'initial_sync' => 'recent_7_days',
        ])->assertOk()->assertJsonPath('data.name', 'Sales Inbox');
        Queue::assertPushed(SyncMailbox::class);
        Queue::assertPushed(RenewMailboxSubscription::class);

        $other = Organization::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
        $otherConnection = IntegrationConnection::create(['organization_id' => $other->id, 'provider' => 'google', 'name' => 'Other', 'status' => 'active', 'external_account_id' => 'other@example.com']);
        $this->getJson("/api/lead-integrations/connections/{$otherConnection->id}/history")->assertNotFound();
    }

    public function test_expired_mailbox_without_refresh_token_requires_reconnection(): void
    {
        $connection = IntegrationConnection::create([
            'organization_id' => $this->owner->organization_id, 'connected_by' => $this->owner->id,
            'provider' => 'google', 'name' => 'Expired Inbox', 'status' => 'active',
            'external_account_id' => 'expired@example.com', 'account_email' => 'expired@example.com',
            'access_token' => 'expired-token', 'token_expires_at' => now()->subMinute(),
        ]);

        $this->postJson("/api/lead-integrations/connections/{$connection->id}/test")
            ->assertStatus(422)
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.message', 'Connection needs authorization or reconnection.');
    }

    private function configureGoogle(): void
    {
        config(['services.google.client_id' => 'google-client', 'services.google.client_secret' => 'google-secret', 'services.google.redirect_uri' => 'https://api.example.com/api/lead-integrations/oauth/google/callback']);
    }

    private function configureMicrosoft(): void
    {
        config(['services.microsoft.client_id' => 'ms-client', 'services.microsoft.client_secret' => 'ms-secret', 'services.microsoft.tenant' => 'common', 'services.microsoft.redirect_uri' => 'https://api.example.com/api/lead-integrations/oauth/microsoft/callback']);
    }
}
