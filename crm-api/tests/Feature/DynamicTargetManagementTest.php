<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckSubscription;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DynamicTargetManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(CheckSubscription::class);
        DB::connection()->getPdo()->sqliteCreateFunction('FIELD', function ($value, ...$choices) {
            $position = array_search($value, $choices, true);

            return $position === false ? 0 : $position + 1;
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->nullable();
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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('plan');
            $table->string('billing_cycle')->nullable();
            $table->string('subscription_source')->default('internal');
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->dateTime('access_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('active');
            $table->unsignedInteger('extra_members_purchased')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('department_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('user_id');
            $table->string('position')->nullable();
            $table->timestamps();
        });

        (require database_path('migrations/2026_10_23_000001_create_dynamic_target_management.php'))->up();
    }

    public function test_owner_can_create_multiple_manual_targets_and_employee_sees_department_shared_progress(): void
    {
        [$owner, $employee, $department] = $this->organizationUsers('alpha');
        Sanctum::actingAs($owner);

        $calls = $this->postJson('/api/performance-targets/definitions', $this->definition('CALLS', 'Calls', 'calls'))
            ->assertCreated()->json('data');
        $meetings = $this->postJson('/api/performance-targets/definitions', $this->definition('MEETINGS', 'Meetings', 'meetings'))
            ->assertCreated()->json('data');

        $response = $this->postJson('/api/performance-targets/assignments', [
            'scope_type' => 'department_shared',
            'department_id' => $department,
            'period_type' => 'monthly',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'targets' => [
                ['target_definition_id' => $calls['id'], 'target_value' => 100],
                ['target_definition_id' => $meetings['id'], 'target_value' => 20],
            ],
        ])->assertCreated()->json('data');

        $this->postJson("/api/performance-targets/assignments/{$response[0]['id']}/manual-entries", [
            'user_id' => $employee->id,
            'entry_date' => '2026-10-12',
            'actual_value' => 25,
            'notes' => 'Verified activity total',
        ])->assertCreated();

        $this->getJson('/api/performance-targets/assignments?user_id='.$employee->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        Sanctum::actingAs($employee);
        $this->getJson('/api/performance-targets/assignments')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.scope_type', 'department_shared')
            ->assertJsonFragment(['target_code' => 'CALLS', 'actual_value' => 25, 'achievement_percentage' => 25]);
    }

    public function test_overlap_percentage_and_automatic_metric_unit_rules_are_enforced(): void
    {
        [$owner, $employee] = $this->organizationUsers('beta');
        Sanctum::actingAs($owner);

        $this->postJson('/api/performance-targets/definitions', $this->definition('CONVERSION', 'Conversion', 'currency', 'lead_conversion_ratio'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_type');

        $definition = $this->postJson('/api/performance-targets/definitions', $this->definition('QUALITY', 'Quality', 'percentage'))
            ->assertCreated()->json('data');
        $payload = [
            'scope_type' => 'employee',
            'user_id' => $employee->id,
            'period_type' => 'custom',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'targets' => [['target_definition_id' => $definition['id'], 'target_value' => 80]],
        ];
        $assignment = $this->postJson('/api/performance-targets/assignments', $payload)->assertCreated()->json('data.0');
        $this->postJson('/api/performance-targets/assignments', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('targets');
        $this->putJson("/api/performance-targets/assignments/{$assignment['id']}", [
            'target_value' => 101,
            'period_type' => 'custom',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => 'active',
        ])->assertUnprocessable()->assertJsonValidationErrors('target_value');
    }

    public function test_management_and_records_are_tenant_scoped(): void
    {
        [$ownerA] = $this->organizationUsers('tenant-a');
        [$ownerB] = $this->organizationUsers('tenant-b');
        Sanctum::actingAs($ownerA);
        $definition = $this->postJson('/api/performance-targets/definitions', $this->definition('A_ONLY', 'A only', 'number'))
            ->assertCreated()->json('data');

        Sanctum::actingAs($ownerB);
        $this->getJson('/api/performance-targets/definitions')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/performance-targets/definitions/{$definition['id']}", $this->definition('A_ONLY', 'Tampered', 'number'))
            ->assertNotFound();
    }

    /** @return array{0:User,1:User,2:int} */
    private function organizationUsers(string $slug): array
    {
        $organization = Organization::create(['name' => $slug, 'slug' => $slug, 'timezone' => 'Asia/Kolkata']);
        $owner = User::forceCreate(['organization_id' => $organization->id, 'name' => "{$slug} owner", 'email' => "owner-{$slug}@example.com", 'password' => 'password', 'role' => 'owner']);
        $employee = User::forceCreate(['organization_id' => $organization->id, 'name' => "{$slug} employee", 'email' => "employee-{$slug}@example.com", 'password' => 'password', 'role' => 'employee']);
        $department = DB::table('departments')->insertGetId(['organization_id' => $organization->id, 'name' => 'Sales', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('department_user')->insert(['department_id' => $department, 'user_id' => $employee->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subscriptions')->insert([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'plan' => 'business',
            'billing_cycle' => 'monthly',
            'subscription_source' => 'internal',
            'is_active' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$owner, $employee, $department];
    }

    /** @return array<string, mixed> */
    private function definition(string $code, string $name, string $unit, string $source = 'manual'): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'description' => null,
            'unit_type' => $unit,
            'unit_label' => null,
            'metric_source' => $source,
            'direction' => 'higher',
            'is_active' => true,
            'department_ids' => [],
        ];
    }
}
