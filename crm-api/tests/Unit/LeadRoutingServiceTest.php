<?php

namespace Tests\Unit;

use App\Models\LeadRoutingRule;
use App\Models\User;
use App\Services\LeadIntegrations\LeadRoutingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeadRoutingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('email');
            $table->string('password')->nullable();
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('integration_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('platform');
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('lead_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('backup_user_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('last_assigned_user_id')->nullable();
            $table->string('name');
            $table->string('source_type');
            $table->string('source_key')->nullable();
            $table->string('strategy')->default('fixed');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        Schema::create('lead_integration_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->unique();
            $table->unsignedBigInteger('default_owner_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_country_and_service_rules_are_previewed_without_changing_round_robin_cursor(): void
    {
        $first = User::forceCreate(['organization_id' => 10, 'name' => 'First', 'email' => 'first@example.test', 'role' => 'employee']);
        $second = User::forceCreate(['organization_id' => 10, 'name' => 'Second', 'email' => 'second@example.test', 'role' => 'employee']);
        $outsider = User::forceCreate(['organization_id' => 20, 'name' => 'Outside', 'email' => 'outside@example.test', 'role' => 'employee']);
        $rule = LeadRoutingRule::create([
            'organization_id' => 10, 'name' => 'India round robin', 'source_type' => 'country',
            'source_key' => 'India', 'strategy' => 'round_robin', 'priority' => 1,
            'settings' => ['user_ids' => [$first->id, $second->id, $outsider->id]],
        ]);

        $service = app(LeadRoutingService::class);
        $preview = $service->preview(10, ['country' => 'India']);
        self::assertSame($first->id, $preview['user_id']);
        self::assertNull($rule->fresh()->last_assigned_user_id);

        $resolved = $service->resolve(10, ['country' => 'India']);
        self::assertSame($first->id, $resolved['user_id']);
        self::assertSame($first->id, $rule->fresh()->last_assigned_user_id);

        $next = $service->resolve(10, ['country' => 'India']);
        self::assertSame($second->id, $next['user_id']);
        self::assertNotSame($outsider->id, $next['user_id']);
    }
}
