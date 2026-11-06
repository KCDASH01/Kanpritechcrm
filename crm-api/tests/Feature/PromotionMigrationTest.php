<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromotionMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('subscription_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->string('plan');
            $table->string('billing_cycle');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        foreach ([['growth', 'monthly', 79900], ['growth', 'yearly', 799000], ['business', 'monthly', 199900], ['business', 'yearly', 1999000]] as [$plan,$cycle,$amount]) {
            DB::table('subscription_plan_prices')->insert(['plan' => $plan, 'billing_cycle' => $cycle, 'currency' => 'INR', 'amount_minor' => $amount, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['promotion_redemption_applications', 'promotion_audit_events', 'promotion_redemptions', 'promotion_campaign_customers', 'promotion_campaign_plan_prices', 'promotion_campaigns', 'subscription_plan_prices', 'subscriptions', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    public function test_additive_migration_creates_disabled_launch20_draft_and_four_inr_mappings(): void
    {
        $migration = require base_path('../admin-dashboard/database/migrations/2026_11_06_000001_create_subscription_promotions.php');
        $migration->up();
        self::assertDatabaseHas('promotion_campaigns', ['code' => 'LAUNCH20', 'state' => 'draft', 'percentage_basis_points' => 2000, 'duration' => 'first_cycle']);
        $campaignId = DB::table('promotion_campaigns')->where('code', 'LAUNCH20')->value('id');
        self::assertSame(4, DB::table('promotion_campaign_plan_prices')->where('promotion_campaign_id', $campaignId)->count());
        self::assertSame(0, DB::table('promotion_campaign_plan_prices')->whereNotNull('gateway_offer_id')->count());
        self::assertTrue(Schema::hasTable('promotion_redemption_applications'));
        $migration->down();
        self::assertFalse(Schema::hasTable('promotion_campaigns'));
    }

    public function test_all_explicit_mysql_identifiers_are_within_sixty_four_characters(): void
    {
        $source = file_get_contents(base_path('../admin-dashboard/database/migrations/2026_11_06_000001_create_subscription_promotions.php'));
        preg_match_all("/'([a-z0-9_]+)'/", $source, $matches);
        $identifiers = array_filter($matches[1], fn ($value) => str_ends_with($value, '_fk') || str_ends_with($value, '_idx') || str_ends_with($value, '_unique'));
        self::assertNotEmpty($identifiers);
        foreach ($identifiers as $identifier) {
            self::assertLessThanOrEqual(64, strlen($identifier), $identifier);
        }
    }
}
