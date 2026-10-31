<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['subscription_transactions', 'subscription_audit_events', 'subscription_gateway_events', 'subscription_plan_prices', 'subscriptions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    public function test_additive_subscription_migration_preserves_legacy_rows_and_seeds_versioned_prices(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('plan')->default('free');
            $table->string('subscription_source')->default('internal');
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('active');
            $table->string('gateway')->nullable();
            $table->string('gateway_subscription_id')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->unsignedInteger('extra_members_purchased')->default(0);
            $table->json('external_metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('subscriptions')->insert([
            'organization_id' => 1, 'user_id' => 1, 'plan' => 'free', 'status' => 'active',
            'subscription_source' => 'internal', 'is_active' => true, 'currency' => 'INR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_29_000001_add_subscription_trials_and_recurring_billing.php');
        $migration->up();

        self::assertTrue(Schema::hasColumns('subscriptions', [
            'billing_cycle', 'provider_status', 'authorized_at', 'trial_ends_at',
            'current_period_end', 'grace_ends_at', 'cancel_requested_at', 'access_until',
        ]));
        self::assertTrue(Schema::hasTable('subscription_gateway_events'));
        self::assertTrue(Schema::hasTable('subscription_audit_events'));
        self::assertTrue(Schema::hasTable('subscription_transactions'));
        self::assertSame('free', DB::table('subscriptions')->value('plan'));
        self::assertSame(8, DB::table('subscription_plan_prices')->count());
        self::assertSame(79900, DB::table('subscription_plan_prices')->where([
            'plan' => 'growth', 'billing_cycle' => 'monthly', 'currency' => 'INR',
        ])->value('amount_minor'));
    }
}
