<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadOwnershipLifecycleTest extends TestCase
{
    private Organization $organization;

    private User $owner;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

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
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('plan');
            $table->string('subscription_source')->default('internal');
            $table->string('billing_cycle')->nullable();
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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->date('lead_date')->nullable();
            $table->unsignedBigInteger('pipeline_id')->nullable();
            $table->unsignedBigInteger('stage_id')->nullable();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('job_title')->nullable();
            $table->string('website')->nullable();
            $table->string('status')->default('new');
            $table->string('source')->default('manual');
            $table->string('types')->nullable();
            $table->string('industry')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('score')->default(0);
            $table->json('custom_fields')->nullable();
            $table->string('external_lead_id')->nullable();
            $table->string('lost_reason')->nullable();
            $table->string('client_type')->nullable();
            $table->string('business_type')->nullable();
            $table->string('market_type')->nullable();
            $table->decimal('expected_value', 15, 2)->nullable();
            $table->string('currency', 3)->default('INR');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('lead_timeline', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('description', 500);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->boolean('is_done')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable();
            $table->string('link')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->timestamps();
        });

        $this->organization = Organization::create(['name' => 'Ownership Org', 'slug' => 'ownership-org', 'is_active' => true]);
        $this->owner = $this->user('Owner', 'owner@example.com', 'owner');
        $this->employee = $this->user('Employee One', 'employee1@example.com');
        Subscription::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->owner->id,
            'plan' => 'enterprise', 'status' => 'active', 'is_active' => true,
        ]);
        Sanctum::actingAs($this->owner);
    }

    public function test_filtered_summary_matches_employee_dataset_independent_of_pagination(): void
    {
        $this->lead($this->employee, 'DOMESTIC', 'new');
        $this->lead($this->employee, 'INTERNATIONAL', 'new');
        $this->lead($this->employee, null, 'new');
        $this->lead($this->employee, 'DOMESTIC', 'lost');
        $other = $this->user('Other', 'other@example.com');
        $this->lead($other, 'DOMESTIC', 'new');

        $this->getJson("/api/leads?assigned_to={$this->employee->id}&status=new&per_page=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.summary.total', 3)
            ->assertJsonPath('meta.summary.domestic', 1)
            ->assertJsonPath('meta.summary.international', 1);
    }

    public function test_zero_lead_employee_has_zero_filtered_totals(): void
    {
        $emptyEmployee = $this->user('Empty Employee', 'empty@example.com');

        $this->getJson("/api/leads?assigned_to={$emptyEmployee->id}&per_page=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.summary.total', 0)
            ->assertJsonPath('meta.summary.domestic', 0)
            ->assertJsonPath('meta.summary.international', 0);
    }

    public function test_new_unassigned_lead_uses_null_and_is_immediately_filterable(): void
    {
        $lead = $this->lead(null, null);

        self::assertNull($lead->assigned_to);
        $this->getJson('/api/leads?unassigned=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.summary.total', 1);
    }

    public function test_removing_employee_unassigns_leads_and_writes_audit_history(): void
    {
        $lead = $this->lead($this->employee, 'DOMESTIC');

        $this->deleteJson("/api/employees/{$this->employee->id}", ['lead_action' => 'unassign'])
            ->assertOk()->assertJsonPath('reassigned_leads', 1);

        self::assertNull($lead->fresh()->assigned_to);
        $this->getJson('/api/leads?unassigned=1')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseHas('lead_timeline', [
            'lead_id' => $lead->id, 'action' => 'assigned', 'user_id' => $this->owner->id,
        ]);
        self::assertSoftDeleted('users', ['id' => $this->employee->id]);
    }

    public function test_employee_removal_can_transfer_leads_to_active_same_org_member(): void
    {
        $target = $this->user('Target', 'target@example.com');
        $lead = $this->lead($this->employee, 'INTERNATIONAL');

        $this->deleteJson("/api/employees/{$this->employee->id}", [
            'lead_action' => 'transfer', 'transfer_to' => $target->id,
        ])->assertOk()->assertJsonPath('reassigned_leads', 1);

        self::assertSame($target->id, $lead->fresh()->assigned_to);
    }

    public function test_cross_organization_transfer_is_rejected_without_partial_changes(): void
    {
        $lead = $this->lead($this->employee, 'DOMESTIC');
        $foreignOrg = Organization::create(['name' => 'Foreign', 'slug' => 'foreign', 'is_active' => true]);
        $foreign = User::forceCreate([
            'organization_id' => $foreignOrg->id, 'name' => 'Foreign User', 'email' => 'foreign@example.com',
            'password' => 'password', 'role' => 'employee', 'is_active' => true,
        ]);

        $this->deleteJson("/api/employees/{$this->employee->id}", [
            'lead_action' => 'transfer', 'transfer_to' => $foreign->id,
        ])->assertUnprocessable();

        self::assertSame($this->employee->id, $lead->fresh()->assigned_to);
        self::assertFalse($this->employee->fresh()->trashed());
    }

    public function test_bulk_reassignment_is_tenant_scoped_and_audited(): void
    {
        $target = $this->user('Target', 'bulk-target@example.com');
        $first = $this->lead($this->employee, 'DOMESTIC');
        $second = $this->lead($this->employee, 'INTERNATIONAL');

        $this->postJson('/api/leads/bulk-reassign', [
            'selection' => 'selected', 'lead_ids' => [$first->id, $second->id],
            'assigned_to' => $target->id,
        ])->assertOk()->assertJsonPath('reassigned_leads', 2);

        self::assertSame(2, Lead::where('assigned_to', $target->id)->count());
        self::assertSame(2, DB::table('lead_timeline')->where('action', 'assigned')->count());
    }

    public function test_deactivate_unassigns_and_reactivate_restores_login_eligibility_without_deleting_history(): void
    {
        $lead = $this->lead($this->employee, 'DOMESTIC');
        $this->postJson("/api/employees/{$this->employee->id}/deactivate", ['lead_action' => 'unassign'])
            ->assertOk()->assertJsonPath('reassigned_leads', 1);
        self::assertFalse($this->employee->fresh()->is_active);
        self::assertNull($lead->fresh()->assigned_to);

        $this->postJson("/api/employees/{$this->employee->id}/reactivate")
            ->assertOk()->assertJsonPath('data.is_active', true);
        self::assertTrue($this->employee->fresh()->is_active);
    }

    public function test_employee_cannot_reassign_leads_or_manage_another_team_member(): void
    {
        $other = $this->user('Other', 'unauthorized-target@example.com');
        $lead = $this->lead($this->employee, 'DOMESTIC');
        Sanctum::actingAs($this->employee);

        $this->postJson('/api/leads/bulk-reassign', [
            'selection' => 'selected', 'lead_ids' => [$lead->id], 'assigned_to' => $other->id,
        ])->assertForbidden();
        $this->deleteJson("/api/employees/{$other->id}", ['lead_action' => 'unassign'])
            ->assertForbidden();

        self::assertSame($this->employee->id, $lead->fresh()->assigned_to);
        self::assertFalse($other->fresh()->trashed());
    }

    private function user(string $name, string $email, string $role = 'employee'): User
    {
        return User::forceCreate([
            'organization_id' => $this->organization->id, 'name' => $name, 'email' => $email,
            'password' => 'password', 'role' => $role, 'is_active' => true,
        ]);
    }

    private function lead(?User $owner, ?string $market, string $status = 'new'): Lead
    {
        return Lead::create([
            'organization_id' => $this->organization->id, 'created_by' => $this->owner->id,
            'assigned_to' => $owner?->id, 'lead_date' => '2026-11-17', 'first_name' => uniqid('Lead ', true),
            'status' => $status, 'source' => 'manual', 'market_type' => $market,
        ]);
    }
}
