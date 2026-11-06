<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 64)->nullable()->unique('promo_campaign_code_unique');
            $table->string('kind', 16)->default('coupon');
            $table->string('discount_type', 16);
            $table->unsignedInteger('percentage_basis_points')->nullable();
            $table->unsignedBigInteger('fixed_amount_minor')->nullable();
            $table->unsignedBigInteger('maximum_discount_minor')->nullable();
            $table->unsignedBigInteger('minimum_eligible_minor')->default(0);
            $table->json('eligible_currencies');
            $table->string('audience', 32)->default('all');
            $table->string('duration', 24)->default('first_cycle');
            $table->unsignedSmallInteger('discount_cycles')->nullable();
            $table->boolean('stackable')->default(false);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->unsignedInteger('total_redemption_limit')->nullable();
            $table->unsignedSmallInteger('per_customer_limit')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('state', 16)->default('draft');
            $table->json('rules')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->unsignedBigInteger('updated_by_admin_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'starts_at', 'ends_at'], 'promo_campaign_state_dates_idx');
            $table->index(['kind', 'priority'], 'promo_campaign_kind_priority_idx');
        });

        Schema::create('promotion_campaign_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promotion_campaign_id');
            $table->unsignedBigInteger('subscription_plan_price_id');
            $table->string('gateway_offer_id', 191)->nullable();
            $table->json('supported_payment_methods')->nullable();
            $table->timestamps();
            $table->foreign('promotion_campaign_id', 'promo_plan_campaign_fk')->references('id')->on('promotion_campaigns')->cascadeOnDelete();
            $table->foreign('subscription_plan_price_id', 'promo_plan_price_fk')->references('id')->on('subscription_plan_prices')->restrictOnDelete();
            $table->unique(['promotion_campaign_id', 'subscription_plan_price_id'], 'promo_campaign_price_unique');
            $table->index('gateway_offer_id', 'promo_gateway_offer_idx');
        });

        Schema::create('promotion_campaign_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promotion_campaign_id');
            $table->unsignedBigInteger('organization_id');
            $table->timestamps();
            $table->foreign('promotion_campaign_id', 'promo_customer_campaign_fk')->references('id')->on('promotion_campaigns')->cascadeOnDelete();
            $table->foreign('organization_id', 'promo_customer_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['promotion_campaign_id', 'organization_id'], 'promo_campaign_org_unique');
        });

        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('reservation_token')->unique('promo_reservation_token_unique');
            $table->string('idempotency_key', 191)->unique('promo_idempotency_unique');
            $table->unsignedBigInteger('promotion_campaign_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('original_amount_minor');
            $table->unsignedBigInteger('expected_discount_minor');
            $table->unsignedBigInteger('actual_discount_minor')->nullable();
            $table->char('currency', 3);
            $table->unsignedSmallInteger('successful_cycles')->default(0);
            $table->string('status', 24)->default('reserved');
            $table->string('gateway', 32)->default('razorpay');
            $table->string('gateway_offer_id', 191)->nullable();
            $table->string('gateway_payment_id', 191)->nullable();
            $table->string('gateway_invoice_id', 191)->nullable();
            $table->timestamp('reserved_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign('promotion_campaign_id', 'promo_redemption_campaign_fk')->references('id')->on('promotion_campaigns')->restrictOnDelete();
            $table->foreign('organization_id', 'promo_redemption_org_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('subscription_id', 'promo_redemption_subscription_fk')->references('id')->on('subscriptions')->nullOnDelete();
            $table->unique(['gateway', 'gateway_payment_id'], 'promo_gateway_payment_unique');
            $table->index(['promotion_campaign_id', 'status'], 'promo_redemption_campaign_status_idx');
            $table->index(['organization_id', 'status'], 'promo_redemption_org_status_idx');
            $table->index(['subscription_id', 'status'], 'promo_redemption_subscription_status_idx');
        });

        Schema::create('promotion_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promotion_campaign_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('actor_admin_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event_type', 64);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('promotion_campaign_id', 'promo_audit_campaign_fk')->references('id')->on('promotion_campaigns')->nullOnDelete();
            $table->index(['promotion_campaign_id', 'created_at'], 'promo_audit_campaign_date_idx');
            $table->index(['organization_id', 'created_at'], 'promo_audit_org_date_idx');
        });

        Schema::create('promotion_redemption_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promotion_redemption_id');
            $table->unsignedSmallInteger('cycle_number');
            $table->unsignedBigInteger('actual_discount_minor')->nullable();
            $table->string('gateway_payment_id', 191);
            $table->string('gateway_invoice_id', 191)->nullable();
            $table->string('status', 16)->default('applied');
            $table->timestamp('applied_at');
            $table->timestamp('refunded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign('promotion_redemption_id', 'promo_application_redemption_fk')->references('id')->on('promotion_redemptions')->cascadeOnDelete();
            $table->unique('gateway_payment_id', 'promo_application_payment_unique');
            $table->unique(['promotion_redemption_id', 'cycle_number'], 'promo_application_cycle_unique');
        });

        $campaignId = DB::table('promotion_campaigns')->insertGetId([
            'name' => 'Launch 20',
            'code' => 'LAUNCH20',
            'kind' => 'coupon',
            'discount_type' => 'percentage',
            'percentage_basis_points' => 2000,
            'eligible_currencies' => json_encode(['INR']),
            'audience' => 'new_customers',
            'duration' => 'first_cycle',
            'discount_cycles' => 1,
            'stackable' => false,
            'priority' => 100,
            'per_customer_limit' => 1,
            'state' => 'draft',
            'rules' => json_encode(['trial_days' => 7, 'requires_gateway_offer_mapping' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('subscription_plan_prices')
            ->where('currency', 'INR')
            ->whereIn('plan', ['growth', 'business'])
            ->whereIn('billing_cycle', ['monthly', 'yearly'])
            ->where('is_active', true)
            ->get(['id'])
            ->each(fn ($price) => DB::table('promotion_campaign_plan_prices')->insert([
                'promotion_campaign_id' => $campaignId,
                'subscription_plan_price_id' => $price->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_redemption_applications');
        Schema::dropIfExists('promotion_audit_events');
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('promotion_campaign_customers');
        Schema::dropIfExists('promotion_campaign_plan_prices');
        Schema::dropIfExists('promotion_campaigns');
    }
};
