<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckSubscription;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionGatewayEvent;
use App\Models\User;
use App\Services\SubscriptionLifecycleService;
use App\Services\SubscriptionWebhookService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionTrialLifecycleTest extends TestCase
{
    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('FIELD', function ($value, ...$choices) {
                $position = array_search($value, $choices, true);

                return $position === false ? 0 : $position + 1;
            });
        }
        config()->set('services.razorpay.key_id', 'rzp_test_local');
        config()->set('services.razorpay.key_secret', 'test_secret');
        config()->set('services.razorpay.webhook_secret', 'test_webhook_secret');
        config()->set('services.razorpay.test_mode', true);
        config()->set('services.razorpay.subscription_total_count.monthly', 120);
        config()->set('services.razorpay.plans.growth.monthly.INR', 'plan_growth_test');

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('email');
            $table->string('password')->nullable();
            $table->string('role');
            $table->boolean('is_sso_user')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('plan');
            $table->string('billing_cycle')->nullable();
            $table->string('subscription_source')->default('internal');
            $table->string('status');
            $table->string('provider_status')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('gateway')->nullable();
            $table->string('gateway_subscription_id')->nullable();
            $table->string('gateway_plan_id')->nullable();
            $table->string('gateway_customer_id')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            foreach (['start_date', 'end_date', 'authorized_at', 'trial_started_at', 'trial_ends_at', 'current_period_start', 'current_period_end', 'next_charge_at', 'grace_ends_at', 'cancel_requested_at', 'cancelled_at', 'access_until', 'last_payment_failed_at'] as $column) {
                $table->dateTime($column)->nullable();
            }
            $table->boolean('cancel_at_period_end')->default(false);
            $table->json('external_metadata')->nullable();
            $table->unsignedInteger('extra_members_purchased')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('subscription_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event_type');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('subscription_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('gateway');
            $table->string('gateway_payment_id')->nullable();
            $table->string('gateway_invoice_id')->nullable();
            $table->string('type');
            $table->string('status');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('failure_code')->nullable();
            $table->text('failure_description')->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'gateway_payment_id']);
        });
        Schema::create('subscription_gateway_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway');
            $table->string('event_id');
            $table->string('event_type');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('gateway_subscription_id')->nullable();
            $table->string('payload_hash');
            $table->json('payload')->nullable();
            $table->string('processing_status')->default('received');
            $table->text('processing_error')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'event_id']);
        });

        $this->organization = Organization::create(['name' => 'Trial Org', 'slug' => 'trial-org']);
        $this->owner = User::forceCreate([
            'organization_id' => $this->organization->id,
            'name' => 'Owner', 'email' => 'owner@example.com', 'role' => 'owner', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['subscription_gateway_events', 'subscription_transactions', 'subscription_audit_events', 'subscriptions', 'users', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    public function test_trial_starts_only_after_signed_server_verified_authorization(): void
    {
        $service = app(SubscriptionLifecycleService::class);
        $pending = $service->createPending($this->organization, $this->owner, 'growth', 'monthly', 'INR');
        $startAt = now()->addDays(7)->timestamp;
        Http::fake(function (Request $request) use ($startAt) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'sub_test_1', 'status' => 'created', 'start_at' => $startAt, 'charge_at' => $startAt], 200);
            }

            return Http::response(['id' => 'sub_test_1', 'status' => 'authenticated', 'plan_id' => 'plan_growth_test', 'customer_id' => 'cust_test', 'start_at' => $startAt, 'charge_at' => $startAt], 200);
        });

        $authorization = $service->startAuthorization($this->organization, $this->owner);
        self::assertFalse($pending->fresh()->is_active);
        self::assertSame('sub_test_1', $authorization['subscription_id']);
        self::assertSame('799.00', $authorization['amount']);

        $paymentId = 'pay_auth_1';
        $signature = hash_hmac('sha256', $paymentId.'|sub_test_1', 'test_secret');
        $active = $service->verifyAuthorization($this->organization, $this->owner, 'sub_test_1', $paymentId, $signature);

        self::assertTrue($active->is_active);
        self::assertSame('trialing', $active->status);
        self::assertNotNull($active->authorized_at);
        self::assertDatabaseHas('subscription_transactions', ['gateway_payment_id' => $paymentId, 'type' => 'authorization']);
    }

    public function test_invalid_checkout_signature_never_activates_access(): void
    {
        $service = app(SubscriptionLifecycleService::class);
        $service->createPending($this->organization, $this->owner, 'growth', 'monthly', 'INR');

        $this->expectException(ValidationException::class);
        try {
            $service->verifyAuthorization($this->organization, $this->owner, 'sub_missing', 'pay_bad', 'invalid');
        } finally {
            self::assertFalse((bool) Subscription::firstOrFail()->is_active);
        }
    }

    public function test_trial_cancellation_prevents_renewal_but_preserves_original_trial_access(): void
    {
        $trialEnd = now()->addDays(7)->startOfSecond();
        Subscription::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'plan' => 'growth', 'billing_cycle' => 'monthly', 'subscription_source' => 'internal',
            'status' => 'trialing', 'provider_status' => 'authenticated', 'is_active' => true,
            'gateway' => 'razorpay', 'gateway_subscription_id' => 'sub_cancel_trial',
            'trial_ends_at' => $trialEnd, 'access_until' => $trialEnd, 'amount' => 799, 'currency' => 'INR',
        ]);
        Http::fake(['*/subscriptions/sub_cancel_trial/cancel' => Http::response(['status' => 'cancelled'], 200)]);

        $cancelled = app(SubscriptionLifecycleService::class)->cancel($this->organization, $this->owner);

        self::assertNotNull($cancelled->cancel_requested_at);
        self::assertFalse($cancelled->cancel_at_period_end);
        self::assertTrue($cancelled->is_active);
        self::assertTrue($cancelled->access_until->equalTo($trialEnd));
    }

    public function test_failed_renewal_webhook_grants_exactly_three_day_grace_period(): void
    {
        $subscription = Subscription::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'plan' => 'business', 'billing_cycle' => 'monthly', 'subscription_source' => 'internal',
            'status' => 'active', 'provider_status' => 'active', 'is_active' => true,
            'gateway' => 'razorpay', 'gateway_subscription_id' => 'sub_grace',
            'amount' => 1999, 'currency' => 'INR',
        ]);
        $event = SubscriptionGatewayEvent::create([
            'gateway' => 'razorpay', 'event_id' => 'evt_pending_1', 'event_type' => 'subscription.pending',
            'gateway_subscription_id' => 'sub_grace', 'payload_hash' => 'hash',
            'payload' => ['event' => 'subscription.pending', 'created_at' => now()->timestamp, 'payload' => ['subscription' => ['entity' => ['id' => 'sub_grace']]]],
        ]);

        app(SubscriptionWebhookService::class)->process($event);
        $subscription->refresh();

        self::assertSame('pending', $subscription->provider_status);
        self::assertNotNull($subscription->last_payment_failed_at);
        self::assertTrue($subscription->grace_ends_at->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
        self::assertTrue($subscription->access_until->equalTo($subscription->grace_ends_at));
    }

    public function test_paid_cancellation_is_scheduled_at_current_period_end(): void
    {
        $periodEnd = now()->addMonth()->startOfSecond();
        Subscription::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->owner->id,
            'plan' => 'business', 'billing_cycle' => 'monthly', 'subscription_source' => 'internal',
            'status' => 'active', 'provider_status' => 'active', 'is_active' => true,
            'gateway' => 'razorpay', 'gateway_subscription_id' => 'sub_cancel_paid',
            'current_period_end' => $periodEnd, 'end_date' => $periodEnd, 'access_until' => $periodEnd,
            'amount' => 1999, 'currency' => 'INR',
        ]);
        Http::fake(['*/subscriptions/sub_cancel_paid/cancel' => Http::response(['status' => 'active'], 200)]);

        $cancelled = app(SubscriptionLifecycleService::class)->cancel($this->organization, $this->owner);

        self::assertTrue($cancelled->cancel_at_period_end);
        self::assertNull($cancelled->cancelled_at);
        self::assertTrue($cancelled->is_active);
        self::assertTrue($cancelled->access_until->equalTo($periodEnd));
    }

    public function test_existing_free_customer_is_preserved_until_verified_trial_activation(): void
    {
        $free = Subscription::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->owner->id,
            'plan' => 'free', 'subscription_source' => 'internal', 'status' => 'active', 'is_active' => true,
        ]);
        $service = app(SubscriptionLifecycleService::class);
        $pending = $service->selectTrial($this->organization, $this->owner, 'growth', 'monthly', 'INR');
        self::assertTrue($free->fresh()->is_active);
        self::assertFalse($pending->is_active);

        $startAt = now()->addDays(7)->timestamp;
        Http::fake(function (Request $request) use ($startAt) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'sub_existing_free', 'status' => 'created', 'start_at' => $startAt, 'charge_at' => $startAt]);
            }

            return Http::response(['id' => 'sub_existing_free', 'status' => 'authenticated', 'plan_id' => 'plan_growth_test', 'start_at' => $startAt, 'charge_at' => $startAt]);
        });
        $service->startAuthorization($this->organization, $this->owner);
        $paymentId = 'pay_existing_free';
        $signature = hash_hmac('sha256', $paymentId.'|sub_existing_free', 'test_secret');
        $service->verifyAuthorization($this->organization, $this->owner, 'sub_existing_free', $paymentId, $signature);

        self::assertFalse($free->fresh()->is_active);
        self::assertSame('cancelled', $free->fresh()->status);
    }

    public function test_duplicate_signed_webhook_is_acknowledged_without_duplicate_charge(): void
    {
        $subscription = Subscription::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->owner->id,
            'plan' => 'growth', 'billing_cycle' => 'monthly', 'subscription_source' => 'internal',
            'status' => 'trialing', 'provider_status' => 'authenticated', 'is_active' => true,
            'gateway' => 'razorpay', 'gateway_subscription_id' => 'sub_duplicate',
            'gateway_plan_id' => 'plan_growth_test',
            'authorized_at' => now(), 'trial_ends_at' => now()->addDays(7),
            'access_until' => now()->addDays(7), 'amount' => 799, 'currency' => 'INR',
        ]);
        $periodEnd = now()->addMonth()->timestamp;
        $raw = json_encode([
            'event' => 'subscription.charged', 'created_at' => now()->timestamp,
            'payload' => [
                'subscription' => ['entity' => [
                    'id' => 'sub_duplicate', 'status' => 'active',
                    'plan_id' => 'plan_growth_test',
                    'current_start' => now()->timestamp, 'current_end' => $periodEnd,
                ]],
                'payment' => ['entity' => [
                    'id' => 'pay_duplicate', 'status' => 'captured', 'amount' => 79900,
                    'currency' => 'INR', 'created_at' => now()->timestamp,
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $raw, 'test_webhook_secret');
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
            'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_duplicate_1',
        ];

        $first = $this->call('POST', '/api/webhooks/razorpay', [], [], [], $server, $raw);
        $second = $this->call('POST', '/api/webhooks/razorpay', [], [], [], $server, $raw);

        $first->assertOk()->assertJson(['received' => true]);
        $second->assertOk()->assertJson(['received' => true, 'duplicate' => true]);
        self::assertSame(1, SubscriptionGatewayEvent::where('event_id', 'evt_duplicate_1')->count());
        self::assertDatabaseCount('subscription_transactions', 1);
        self::assertSame('active', $subscription->fresh()->status);
    }

    public function test_successful_renewal_clears_grace_and_extends_access(): void
    {
        $subscription = Subscription::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->owner->id,
            'plan' => 'business', 'billing_cycle' => 'monthly', 'subscription_source' => 'internal',
            'status' => 'active', 'provider_status' => 'pending', 'is_active' => true,
            'gateway' => 'razorpay', 'gateway_subscription_id' => 'sub_renewal',
            'gateway_plan_id' => 'plan_business_test',
            'grace_ends_at' => now()->addDays(3), 'last_payment_failed_at' => now(),
            'access_until' => now()->addDays(3), 'amount' => 1999, 'currency' => 'INR',
        ]);
        $periodEnd = now()->addMonth()->startOfSecond();
        $event = SubscriptionGatewayEvent::create([
            'gateway' => 'razorpay', 'event_id' => 'evt_renewal_1', 'event_type' => 'subscription.charged',
            'gateway_subscription_id' => 'sub_renewal', 'payload_hash' => 'renewal-hash',
            'payload' => [
                'event' => 'subscription.charged', 'created_at' => now()->timestamp,
                'payload' => ['subscription' => ['entity' => [
                    'id' => 'sub_renewal', 'plan_id' => 'plan_business_test',
                    'current_start' => now()->timestamp,
                    'current_end' => $periodEnd->timestamp,
                ]]],
            ],
        ]);

        app(SubscriptionWebhookService::class)->process($event);
        $subscription->refresh();

        self::assertSame('active', $subscription->provider_status);
        self::assertNull($subscription->grace_ends_at);
        self::assertNull($subscription->last_payment_failed_at);
        self::assertSame($periodEnd->timestamp, $subscription->access_until->timestamp);
    }

    public function test_mismatched_webhook_plan_never_activates_subscription(): void
    {
        $subscription = Subscription::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->owner->id,
            'plan' => 'growth', 'billing_cycle' => 'monthly', 'subscription_source' => 'internal',
            'status' => 'pending_authorization', 'provider_status' => 'created', 'is_active' => false,
            'gateway' => 'razorpay', 'gateway_subscription_id' => 'sub_wrong_plan',
            'gateway_plan_id' => 'plan_growth_test', 'amount' => 799, 'currency' => 'INR',
        ]);
        $event = SubscriptionGatewayEvent::create([
            'gateway' => 'razorpay', 'event_id' => 'evt_wrong_plan', 'event_type' => 'subscription.authenticated',
            'gateway_subscription_id' => 'sub_wrong_plan', 'payload_hash' => 'wrong-plan-hash',
            'payload' => [
                'event' => 'subscription.authenticated', 'created_at' => now()->timestamp,
                'payload' => ['subscription' => ['entity' => [
                    'id' => 'sub_wrong_plan', 'plan_id' => 'plan_business_test',
                    'start_at' => now()->addDays(7)->timestamp,
                ]]],
            ],
        ]);

        try {
            app(SubscriptionWebhookService::class)->process($event);
            self::fail('A mismatched Razorpay plan must not activate access.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Razorpay subscription plan does not match the locally authorized plan.', $exception->getMessage());
        }

        self::assertFalse($subscription->fresh()->is_active);
        self::assertSame('pending_authorization', $subscription->fresh()->status);
        self::assertSame('failed', $event->fresh()->processing_status);
    }

    public function test_expired_recurring_subscription_keeps_reads_and_blocks_writes(): void
    {
        Subscription::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->owner->id,
            'plan' => 'growth', 'billing_cycle' => 'monthly', 'subscription_source' => 'internal',
            'status' => 'active', 'provider_status' => 'pending', 'is_active' => true,
            'gateway' => 'razorpay', 'gateway_subscription_id' => 'sub_expired_read_only',
            'authorized_at' => now()->subDays(10), 'last_payment_failed_at' => now()->subDays(4),
            'grace_ends_at' => now()->subDay(), 'access_until' => now()->subDay(),
            'end_date' => now()->subDay(), 'amount' => 799, 'currency' => 'INR',
        ]);

        $this->artisan('subscriptions:expire')->assertSuccessful();
        $middleware = app(CheckSubscription::class);
        $read = \Illuminate\Http\Request::create('/api/dashboard', 'GET');
        $read->setUserResolver(fn () => $this->owner->fresh());
        $write = \Illuminate\Http\Request::create('/api/leads', 'POST');
        $write->setUserResolver(fn () => $this->owner->fresh());

        $readResponse = $middleware->handle($read, fn () => response()->json(['ok' => true]));
        $writeResponse = $middleware->handle($write, fn () => response()->json(['ok' => true]));

        self::assertSame(200, $readResponse->getStatusCode());
        self::assertSame(403, $writeResponse->getStatusCode());
        self::assertDatabaseHas('subscriptions', ['gateway_subscription_id' => 'sub_expired_read_only', 'status' => 'expired', 'is_active' => false]);
    }
}
