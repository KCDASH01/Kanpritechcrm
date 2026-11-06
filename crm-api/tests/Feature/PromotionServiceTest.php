<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PromotionCampaign;
use App\Models\PromotionRedemption;
use App\Models\Subscription;
use App\Models\SubscriptionGatewayEvent;
use App\Models\SubscriptionPlanPrice;
use App\Services\PromotionReconciliationService;
use App\Services\PromotionService;
use App\Services\RazorpayPromotionAdapter;
use App\Services\SubscriptionWebhookService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PromotionServiceTest extends TestCase
{
    private Organization $organization;

    private SubscriptionPlanPrice $price;

    private PromotionCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.promotions.enabled', true);
        config()->set('services.promotions.razorpay_subscription_offers_enabled', false);
        config()->set('services.promotions.reservation_minutes', 30);
        $this->schema();
        $this->organization = Organization::create(['name' => 'Promo Org', 'slug' => 'promo-org']);
        $this->price = SubscriptionPlanPrice::create([
            'plan' => 'growth', 'billing_cycle' => 'monthly', 'currency' => 'INR',
            'amount_minor' => 79900, 'gateway' => 'razorpay', 'is_active' => true,
            'is_public' => true, 'tax_exclusive' => true,
        ]);
        $this->campaign = PromotionCampaign::create([
            'name' => 'Launch 20', 'code' => 'LAUNCH20', 'kind' => 'coupon',
            'discount_type' => 'percentage', 'percentage_basis_points' => 2000,
            'minimum_eligible_minor' => 0, 'eligible_currencies' => ['INR'],
            'audience' => 'new_customers', 'duration' => 'first_cycle',
            'discount_cycles' => 1, 'stackable' => false, 'priority' => 100,
            'per_customer_limit' => 1, 'state' => 'active',
        ]);
        $this->campaign->planPrices()->attach($this->price->id, ['gateway_offer_id' => 'offer_launch20']);
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['promotion_redemption_applications', 'promotion_audit_events', 'promotion_redemptions', 'promotion_campaign_customers', 'promotion_campaign_plan_prices', 'promotion_campaigns', 'subscription_gateway_events', 'subscription_audit_events', 'subscription_transactions', 'subscription_plan_prices', 'subscriptions', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    public function test_percentage_coupon_quotes_exact_integer_subunits(): void
    {
        $quote = app(PromotionService::class)->quote($this->organization, 'growth', 'monthly', 'INR', 79900, 'launch20');
        self::assertSame(15980, $quote['discount_minor']);
        self::assertSame(63920, $quote['first_billing_amount_minor']);
        self::assertSame(79900, $quote['regular_renewal_amount_minor']);
        self::assertTrue($quote['gateway_supported']);
    }

    public function test_annual_plan_uses_its_exact_eligible_price_and_cap(): void
    {
        $annual = SubscriptionPlanPrice::create(['plan' => 'growth', 'billing_cycle' => 'yearly', 'currency' => 'INR', 'amount_minor' => 799000, 'gateway' => 'razorpay', 'is_active' => true, 'is_public' => true, 'tax_exclusive' => true]);
        $this->campaign->planPrices()->attach($annual->id, ['gateway_offer_id' => 'offer_launch20_yearly']);
        $this->campaign->update(['maximum_discount_minor' => 50000]);
        $quote = app(PromotionService::class)->quote($this->organization, 'growth', 'yearly', 'INR', 799000, 'LAUNCH20');
        self::assertSame(50000, $quote['discount_minor']);
        self::assertSame(749000, $quote['first_billing_amount_minor']);
    }

    public function test_percentage_cap_and_fixed_amount_never_exceed_price(): void
    {
        $this->campaign->update(['maximum_discount_minor' => 10000]);
        self::assertSame(10000, app(PromotionService::class)->quote($this->organization, 'growth', 'monthly', 'INR', 79900, 'LAUNCH20')['discount_minor']);
        $this->campaign->update(['discount_type' => 'fixed', 'percentage_basis_points' => null, 'fixed_amount_minor' => 999999, 'maximum_discount_minor' => null]);
        self::assertSame(79900, app(PromotionService::class)->quote($this->organization, 'growth', 'monthly', 'INR', 79900, 'LAUNCH20')['discount_minor']);
    }

    public function test_disabled_expired_and_minimum_amount_rules_fail_closed(): void
    {
        foreach ([['state' => 'disabled'], ['state' => 'active', 'ends_at' => now()->subMinute()], ['state' => 'active', 'ends_at' => null, 'minimum_eligible_minor' => 80000]] as $attributes) {
            $this->campaign->update($attributes);
            try {
                app(PromotionService::class)->quote($this->organization, 'growth', 'monthly', 'INR', 79900, 'LAUNCH20');
                self::fail('Expected coupon rejection.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('coupon_code', $exception->errors());
            }
        }
    }

    public function test_new_customer_coupon_rejects_an_organization_with_a_successful_paid_cycle(): void
    {
        DB::table('subscription_transactions')->insert([
            'organization_id' => $this->organization->id, 'gateway' => 'razorpay',
            'gateway_payment_id' => 'pay_old', 'type' => 'subscription_charge', 'status' => 'captured',
            'amount_minor' => 79900, 'currency' => 'INR', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(ValidationException::class);
        app(PromotionService::class)->quote($this->organization, 'growth', 'monthly', 'INR', 79900, 'LAUNCH20');
    }

    public function test_authorization_only_does_not_disqualify_a_new_customer(): void
    {
        DB::table('subscription_transactions')->insert([
            'organization_id' => $this->organization->id, 'gateway' => 'razorpay',
            'gateway_payment_id' => 'pay_authorization', 'type' => 'subscription_charge', 'status' => 'authorized',
            'amount_minor' => 0, 'currency' => 'INR', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $quote = app(PromotionService::class)->quote($this->organization, 'growth', 'monthly', 'INR', 79900, 'LAUNCH20');
        self::assertSame(15980, $quote['discount_minor']);
    }

    public function test_reservation_is_atomic_and_idempotent_for_the_same_checkout(): void
    {
        $subscription = $this->subscription();
        $first = app(PromotionService::class)->reserve($this->organization, $subscription, 'LAUNCH20');
        $second = app(PromotionService::class)->reserve($this->organization, $subscription, 'LAUNCH20');
        self::assertSame($first->id, $second->id);
        self::assertSame(1, PromotionRedemption::count());
        self::assertSame('reserved', $second->status);
    }

    public function test_provider_adapter_refuses_discount_when_subscription_offers_are_disabled(): void
    {
        $redemption = app(PromotionService::class)->reserve($this->organization, $this->subscription(), 'LAUNCH20');
        $this->expectException(ValidationException::class);
        app(RazorpayPromotionAdapter::class)->applyToSubscriptionPayload(['notes' => []], $redemption);
    }

    public function test_feature_flag_disables_validation_without_querying_provider(): void
    {
        config()->set('services.promotions.enabled', false);
        $this->expectException(ValidationException::class);
        app(PromotionService::class)->quote($this->organization, 'growth', 'monthly', 'INR', 79900, 'LAUNCH20');
    }

    public function test_successful_charge_consumes_first_cycle_only_after_matching_gateway_offer(): void
    {
        $subscription = $this->subscription();
        $redemption = app(PromotionService::class)->reserve($this->organization, $subscription, 'LAUNCH20');
        $subscription->update(['external_metadata' => ['promotion_redemption_id' => $redemption->id]]);
        app(PromotionReconciliationService::class)->paymentSucceeded(
            $subscription->fresh(), ['offer_id' => 'offer_launch20'],
            ['id' => 'pay_discounted', 'status' => 'captured', 'amount' => 63920, 'currency' => 'INR']
        );
        $redemption->refresh();
        self::assertSame('exhausted', $redemption->status);
        self::assertSame(1, $redemption->successful_cycles);
        self::assertSame(15980, $redemption->actual_discount_minor);
    }

    public function test_failed_or_mismatched_gateway_payment_does_not_consume_coupon(): void
    {
        $subscription = $this->subscription();
        $redemption = app(PromotionService::class)->reserve($this->organization, $subscription, 'LAUNCH20');
        $subscription->update(['external_metadata' => ['promotion_redemption_id' => $redemption->id]]);
        app(PromotionReconciliationService::class)->paymentSucceeded($subscription->fresh(), ['offer_id' => 'offer_launch20'], ['id' => 'pay_failed', 'status' => 'failed', 'amount' => 63920]);
        self::assertSame('reserved', $redemption->fresh()->status);
        app(PromotionReconciliationService::class)->paymentSucceeded($subscription->fresh(), ['offer_id' => 'offer_other'], ['id' => 'pay_wrong', 'status' => 'captured', 'amount' => 63920]);
        self::assertSame('failed', $redemption->fresh()->status);
        self::assertSame(0, $redemption->fresh()->successful_cycles);
    }

    public function test_authorized_but_uncaptured_payment_does_not_consume_coupon(): void
    {
        $subscription = $this->subscription();
        $redemption = app(PromotionService::class)->reserve($this->organization, $subscription, 'LAUNCH20');
        $subscription->update(['external_metadata' => ['promotion_redemption_id' => $redemption->id]]);
        app(PromotionReconciliationService::class)->paymentSucceeded(
            $subscription->fresh(), ['offer_id' => 'offer_launch20'],
            ['id' => 'pay_authorized_only', 'status' => 'authorized', 'amount' => 63920]
        );
        self::assertSame('reserved', $redemption->fresh()->status);
        self::assertSame(0, $redemption->fresh()->successful_cycles);
    }

    public function test_refund_is_reconciled_from_the_original_gateway_payment_application(): void
    {
        $subscription = $this->subscription();
        $redemption = app(PromotionService::class)->reserve($this->organization, $subscription, 'LAUNCH20');
        $subscription->update(['external_metadata' => ['promotion_redemption_id' => $redemption->id]]);
        $service = app(PromotionReconciliationService::class);
        $service->paymentSucceeded($subscription->fresh(), ['offer_id' => 'offer_launch20'], ['id' => 'pay_refund', 'status' => 'captured', 'amount' => 70000], ['discount_amount' => 15980]);
        $service->paymentRefunded(['id' => 'pay_refund']);
        self::assertSame('refunded', $redemption->fresh()->status);
        self::assertDatabaseHas('promotion_redemption_applications', ['gateway_payment_id' => 'pay_refund', 'status' => 'refunded', 'actual_discount_minor' => 15980]);
    }

    public function test_refund_webhook_resolves_the_original_payment_without_a_payment_entity(): void
    {
        $subscription = $this->subscription();
        $redemption = app(PromotionService::class)->reserve($this->organization, $subscription, 'LAUNCH20');
        $subscription->update(['external_metadata' => ['promotion_redemption_id' => $redemption->id]]);
        app(PromotionReconciliationService::class)->paymentSucceeded(
            $subscription->fresh(), ['offer_id' => 'offer_launch20'],
            ['id' => 'pay_refund_event', 'status' => 'captured', 'amount' => 63920]
        );
        DB::table('subscription_transactions')->insert([
            'organization_id' => $this->organization->id, 'subscription_id' => $subscription->id,
            'gateway' => 'razorpay', 'gateway_payment_id' => 'pay_refund_event',
            'type' => 'subscription_charge', 'status' => 'captured', 'amount_minor' => 63920,
            'currency' => 'INR', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $event = SubscriptionGatewayEvent::create([
            'gateway' => 'razorpay', 'event_id' => 'evt_refund_entity', 'event_type' => 'refund.processed',
            'payload_hash' => 'refund-hash', 'payload' => [
                'event' => 'refund.processed',
                'payload' => ['refund' => ['entity' => ['id' => 'rfnd_1', 'payment_id' => 'pay_refund_event']]],
            ],
        ]);

        self::assertSame('processed', app(SubscriptionWebhookService::class)->process($event));
        self::assertSame('refunded', $redemption->fresh()->status);
        self::assertDatabaseHas('promotion_redemption_applications', [
            'gateway_payment_id' => 'pay_refund_event', 'status' => 'refunded',
        ]);
    }

    public function test_scheduler_does_not_activate_a_campaign_without_an_offer_mapping(): void
    {
        $this->campaign->update(['state' => 'scheduled', 'starts_at' => now()->subMinute()]);
        $this->campaign->planPrices()->updateExistingPivot($this->price->id, ['gateway_offer_id' => null]);

        $this->artisan('promotions:sync-states')->assertSuccessful();
        self::assertSame('scheduled', $this->campaign->fresh()->state);

        $this->campaign->planPrices()->updateExistingPivot($this->price->id, ['gateway_offer_id' => 'offer_launch20']);
        $this->artisan('promotions:sync-states')->assertSuccessful();
        self::assertSame('active', $this->campaign->fresh()->state);
    }

    public function test_per_customer_limit_blocks_a_second_subscription_after_consumption(): void
    {
        $first = $this->subscription();
        $redemption = app(PromotionService::class)->reserve($this->organization, $first, 'LAUNCH20');
        $redemption->update(['status' => 'exhausted', 'successful_cycles' => 1]);
        $this->expectException(ValidationException::class);
        app(PromotionService::class)->reserve($this->organization, $this->subscription(), 'LAUNCH20');
    }

    public function test_automatic_campaign_can_reserve_without_a_coupon_code(): void
    {
        $this->campaign->update(['kind' => 'automatic', 'code' => null, 'audience' => 'all']);
        $redemption = app(PromotionService::class)->reserve($this->organization, $this->subscription(), null);
        self::assertSame($this->campaign->id, $redemption->promotion_campaign_id);
        self::assertSame('reserved', $redemption->status);
    }

    private function subscription(): Subscription
    {
        return Subscription::create([
            'organization_id' => $this->organization->id, 'plan' => 'growth',
            'billing_cycle' => 'monthly', 'subscription_source' => 'internal',
            'status' => 'trialing', 'is_active' => false, 'amount' => '799.00', 'currency' => 'INR',
        ]);
    }

    private function schema(): void
    {
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug');
            $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('plan');
            $t->string('billing_cycle')->nullable();
            $t->string('subscription_source')->default('internal');
            $t->string('status');
            $t->string('provider_status')->nullable();
            $t->boolean('is_active')->default(false);
            $t->string('gateway')->nullable();
            $t->string('gateway_subscription_id')->nullable();
            $t->string('gateway_plan_id')->nullable();
            $t->decimal('amount', 12, 2)->nullable();
            $t->char('currency', 3)->nullable();
            $t->json('external_metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('subscription_plan_prices', function (Blueprint $t) {
            $t->id();
            $t->string('plan');
            $t->string('billing_cycle');
            $t->char('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->string('gateway')->default('razorpay');
            $t->string('gateway_plan_id')->nullable();
            $t->boolean('tax_exclusive')->default(true);
            $t->boolean('is_public')->default(true);
            $t->boolean('is_active')->default(true);
            $t->dateTime('effective_from')->nullable();
            $t->dateTime('effective_until')->nullable();
            $t->timestamps();
        });
        Schema::create('subscription_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('subscription_id')->nullable();
            $t->string('gateway');
            $t->string('gateway_payment_id')->nullable();
            $t->string('gateway_invoice_id')->nullable();
            $t->string('type');
            $t->string('status');
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->dateTime('occurred_at')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('subscription_gateway_events', function (Blueprint $t) {
            $t->id();
            $t->string('gateway');
            $t->string('event_id')->unique();
            $t->string('event_type');
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->unsignedBigInteger('subscription_id')->nullable();
            $t->string('gateway_subscription_id')->nullable();
            $t->string('payload_hash');
            $t->json('payload');
            $t->string('processing_status')->default('received');
            $t->text('processing_error')->nullable();
            $t->dateTime('processed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('subscription_audit_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('subscription_id')->nullable();
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('event_type');
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->json('metadata')->nullable();
            $t->dateTime('created_at')->nullable();
        });
        Schema::create('promotion_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code')->nullable();
            $t->string('kind');
            $t->string('discount_type');
            $t->unsignedInteger('percentage_basis_points')->nullable();
            $t->unsignedBigInteger('fixed_amount_minor')->nullable();
            $t->unsignedBigInteger('maximum_discount_minor')->nullable();
            $t->unsignedBigInteger('minimum_eligible_minor')->default(0);
            $t->json('eligible_currencies');
            $t->string('audience');
            $t->string('duration');
            $t->unsignedSmallInteger('discount_cycles')->nullable();
            $t->boolean('stackable')->default(false);
            $t->unsignedSmallInteger('priority');
            $t->unsignedInteger('total_redemption_limit')->nullable();
            $t->unsignedSmallInteger('per_customer_limit');
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('ends_at')->nullable();
            $t->string('state');
            $t->json('rules')->nullable();
            $t->dateTime('archived_at')->nullable();
            $t->timestamps();
        });
        Schema::create('promotion_campaign_plan_prices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('promotion_campaign_id');
            $t->unsignedBigInteger('subscription_plan_price_id');
            $t->string('gateway_offer_id')->nullable();
            $t->json('supported_payment_methods')->nullable();
            $t->timestamps();
        });
        Schema::create('promotion_campaign_customers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('promotion_campaign_id');
            $t->unsignedBigInteger('organization_id');
            $t->timestamps();
        });
        Schema::create('promotion_redemptions', function (Blueprint $t) {
            $t->id();
            $t->uuid('reservation_token')->unique();
            $t->string('idempotency_key')->unique();
            $t->unsignedBigInteger('promotion_campaign_id');
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('subscription_id')->nullable();
            $t->unsignedBigInteger('original_amount_minor');
            $t->unsignedBigInteger('expected_discount_minor');
            $t->unsignedBigInteger('actual_discount_minor')->nullable();
            $t->char('currency', 3);
            $t->unsignedSmallInteger('successful_cycles')->default(0);
            $t->string('status');
            $t->string('gateway');
            $t->string('gateway_offer_id')->nullable();
            $t->string('gateway_payment_id')->nullable();
            $t->string('gateway_invoice_id')->nullable();
            $t->dateTime('reserved_at');
            $t->dateTime('expires_at')->nullable();
            $t->dateTime('applied_at')->nullable();
            $t->dateTime('released_at')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('promotion_audit_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('promotion_campaign_id')->nullable();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->unsignedBigInteger('actor_admin_id')->nullable();
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('event_type');
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->json('metadata')->nullable();
            $t->dateTime('created_at')->nullable();
        });
        Schema::create('promotion_redemption_applications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('promotion_redemption_id');
            $t->unsignedSmallInteger('cycle_number');
            $t->unsignedBigInteger('actual_discount_minor')->nullable();
            $t->string('gateway_payment_id')->unique();
            $t->string('gateway_invoice_id')->nullable();
            $t->string('status');
            $t->dateTime('applied_at');
            $t->dateTime('refunded_at')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }
}
