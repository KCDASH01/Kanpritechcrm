<?php

namespace Tests\Feature;

use App\Http\Middleware\BusinessOnly;
use App\Http\Middleware\CheckSubscription;
use App\Jobs\ProcessIntegrationEvent;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppEmbeddedSignupTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([CheckSubscription::class, BusinessOnly::class]);
        Cache::flush();
        Queue::fake();

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
        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('provider', 32);
            $table->string('external_event_id');
            $table->string('event_type', 64);
            $table->string('status', 32)->default('pending');
            $table->string('classification', 64)->nullable();
            $table->json('payload')->nullable();
            $table->json('extracted_data')->nullable();
            $table->string('assignment_reason')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_event_id']);
        });

        $organization = Organization::create(['name' => 'Acme', 'slug' => 'acme', 'is_active' => true]);
        $this->owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'email' => 'owner@example.com', 'role' => 'owner', 'is_active' => true]);
        Sanctum::actingAs($this->owner);
        config(['services.meta' => [
            'app_id' => 'public-app-id', 'app_secret' => 'server-secret', 'graph_version' => 'v22.0',
            'whatsapp_config_id' => 'public-config-id', 'whatsapp_feature_type' => 'whatsapp_business_app_onboarding',
            'whatsapp_signup_version' => 'v4', 'whatsapp_session_info_version' => '3',
        ]]);
    }

    public function test_owner_completes_verified_signup_without_exposing_or_storing_plaintext_token(): void
    {
        $this->fakeMetaSuccess();
        $start = $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/start')
            ->assertOk()->assertJsonMissing(['app_secret'])->json('data');

        $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/complete', [
            'session_id' => $start['session_id'], 'code' => 'one-time-code',
            'waba_id' => '10001', 'phone_number_id' => '20002',
        ])->assertCreated()->assertJsonMissing(['access_token', 'server-secret', 'token-value']);

        $this->assertDatabaseHas('integration_connections', [
            'organization_id' => $this->owner->organization_id, 'provider' => 'whatsapp',
            'external_account_id' => '10001', 'status' => 'active', 'webhook_status' => 'subscribed',
        ]);
        $this->assertDatabaseHas('integration_assets', [
            'organization_id' => $this->owner->organization_id, 'provider' => 'whatsapp',
            'asset_type' => 'phone_number', 'external_id' => '20002', 'parent_external_id' => '10001', 'is_selected' => true,
        ]);
        $rawToken = Schema::getConnection()->table('integration_connections')->value('access_token');
        $this->assertNotSame('token-value', $rawToken);
        $this->assertSame('token-value', IntegrationConnection::firstOrFail()->access_token);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/10001/subscribed_apps'));
    }

    public function test_session_is_tenant_bound_single_use_and_employees_are_forbidden(): void
    {
        $employee = User::create(['organization_id' => $this->owner->organization_id, 'name' => 'Employee', 'email' => 'employee@example.com', 'role' => 'employee', 'is_active' => true]);
        Sanctum::actingAs($employee);
        $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/start')->assertForbidden();

        Sanctum::actingAs($this->owner);
        $start = $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/start')->assertOk()->json('data');
        $other = Organization::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
        $otherOwner = User::create(['organization_id' => $other->id, 'name' => 'Other Owner', 'email' => 'other@example.com', 'role' => 'owner', 'is_active' => true]);
        Sanctum::actingAs($otherOwner);
        $payload = ['session_id' => $start['session_id'], 'code' => 'code', 'waba_id' => '10001', 'phone_number_id' => '20002'];
        $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/complete', $payload)->assertUnprocessable();

        Sanctum::actingAs($this->owner);
        $this->fakeMetaSuccess();
        $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/complete', $payload)->assertCreated();
        $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/complete', $payload)->assertUnprocessable();
    }

    public function test_invalid_asset_relationship_and_subscription_failure_do_not_activate_connection(): void
    {
        Http::fake([
            '*oauth/access_token*' => Http::response(['access_token' => 'token-value']),
            '*10001/phone_numbers*' => Http::response(['data' => [['id' => 'different-phone']]]),
            '*10001*' => Http::response(['id' => '10001', 'name' => 'Acme WABA']),
        ]);
        $start = $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/start')->assertOk()->json('data');
        $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/complete', [
            'session_id' => $start['session_id'], 'code' => 'code', 'waba_id' => '10001', 'phone_number_id' => '20002',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('integration_connections', 0);

        Http::fake([
            '*oauth/access_token*' => Http::response(['access_token' => 'token-value']),
            '*10001/phone_numbers*' => Http::response(['data' => [['id' => '20002', 'display_phone_number' => '+911234567890']]]),
            '*10001/subscribed_apps*' => Http::response(['success' => false], 400),
            '*10001*' => Http::response(['id' => '10001', 'name' => 'Acme WABA']),
        ]);
        $retry = $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/start')->assertOk()->json('data');
        $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/complete', [
            'session_id' => $retry['session_id'], 'code' => 'code', 'waba_id' => '10001', 'phone_number_id' => '20002',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('integration_connections', 0);
    }

    public function test_assets_already_claimed_by_another_organization_are_rejected(): void
    {
        $other = Organization::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
        $connection = IntegrationConnection::create([
            'organization_id' => $other->id, 'provider' => 'whatsapp', 'name' => 'Other WABA',
            'status' => 'active', 'external_account_id' => '10001',
        ]);
        $connection->assets()->create([
            'organization_id' => $other->id, 'provider' => 'whatsapp', 'asset_type' => 'phone_number',
            'external_id' => '20002', 'name' => '+911234567890', 'status' => 'active', 'is_selected' => true,
        ]);
        $this->fakeMetaSuccess();
        $start = $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/start')->assertOk()->json('data');
        $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/complete', [
            'session_id' => $start['session_id'], 'code' => 'code', 'waba_id' => '10001', 'phone_number_id' => '20002',
        ])->assertConflict();
        $this->assertSame(1, IntegrationConnection::count());
    }

    public function test_signed_click_to_whatsapp_webhook_routes_by_phone_and_is_idempotent(): void
    {
        $connection = $this->activeWhatsAppConnection('10001', '20002');
        $payload = [
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '20002'],
                'contacts' => [['profile' => ['name' => 'Prospect']]],
                'messages' => [[
                    'id' => 'wamid.unique', 'from' => '919999999999', 'timestamp' => '1793220000',
                    'text' => ['body' => 'I am interested'],
                    'referral' => ['source_id' => 'ad-123', 'source_type' => 'ad', 'source_url' => 'https://fb.me/ad', 'ctwa_clid' => 'click-123'],
                ]],
            ]]]]],
        ];

        $this->postSignedWebhook($payload)->assertOk();
        $this->postSignedWebhook($payload)->assertOk();

        $this->assertDatabaseCount('integration_events', 1);
        $this->assertDatabaseHas('integration_events', [
            'organization_id' => $this->owner->organization_id,
            'connection_id' => $connection->id,
            'provider' => 'whatsapp',
            'external_event_id' => 'wamid.unique',
            'event_type' => 'click_to_whatsapp',
        ]);
        Queue::assertPushed(ProcessIntegrationEvent::class, 1);
    }

    public function test_normal_whatsapp_message_is_not_classified_as_an_ad_lead(): void
    {
        $this->activeWhatsAppConnection('10001', '20002');
        $payload = [
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '20002'],
                'messages' => [['id' => 'wamid.normal', 'from' => '919999999999', 'text' => ['body' => 'Hello']]],
            ]]]]],
        ];

        $this->postSignedWebhook($payload)->assertOk();
        $this->assertDatabaseCount('integration_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_invalid_whatsapp_webhook_signature_is_rejected(): void
    {
        $this->activeWhatsAppConnection('10001', '20002');
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
        ], '{"entry":[]}')->assertUnauthorized();
        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_duplicate_connection_for_same_number_is_rejected(): void
    {
        $this->fakeMetaSuccess();
        $first = $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/start')->assertOk()->json('data');
        $payload = ['code' => 'code', 'waba_id' => '10001', 'phone_number_id' => '20002'];
        $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/complete', ['session_id' => $first['session_id'], ...$payload])->assertCreated();

        $second = $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/start')->assertOk()->json('data');
        $this->postJson('/api/lead-integrations/whatsapp/embedded-signup/complete', ['session_id' => $second['session_id'], ...$payload])->assertConflict();
        $this->assertDatabaseCount('integration_connections', 1);
    }

    private function activeWhatsAppConnection(string $wabaId, string $phoneId): IntegrationConnection
    {
        $connection = IntegrationConnection::create([
            'organization_id' => $this->owner->organization_id,
            'connected_by' => $this->owner->id,
            'provider' => 'whatsapp',
            'name' => 'Acme WhatsApp',
            'status' => 'active',
            'external_account_id' => $wabaId,
            'access_token' => 'encrypted-by-model',
            'settings' => ['eligibility_verified' => true, 'embedded_signup_completed' => true],
        ]);
        $connection->assets()->create([
            'organization_id' => $this->owner->organization_id,
            'provider' => 'whatsapp',
            'asset_type' => 'phone_number',
            'external_id' => $phoneId,
            'parent_external_id' => $wabaId,
            'name' => '+911234567890',
            'status' => 'active',
            'is_selected' => true,
        ]);

        return $connection;
    }

    private function postSignedWebhook(array $payload)
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $json, 'server-secret');

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $json);
    }

    private function fakeMetaSuccess(): void
    {
        Http::fake([
            '*oauth/access_token*' => Http::response(['access_token' => 'token-value']),
            '*10001/phone_numbers*' => Http::response(['data' => [[
                'id' => '20002', 'display_phone_number' => '+911234567890', 'verified_name' => 'Acme',
                'quality_rating' => 'GREEN', 'platform_type' => 'CLOUD_API',
            ]]]),
            '*10001/subscribed_apps*' => Http::response(['success' => true]),
            '*10001*' => Http::response(['id' => '10001', 'name' => 'Acme WABA', 'account_review_status' => 'APPROVED']),
        ]);
    }
}
