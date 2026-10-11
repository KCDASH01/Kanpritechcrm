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

class LeadBusinessTypeTest extends TestCase
{
    private User $owner;

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
            $table->string('subscription_source')->default('internal');
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('first_name');
            $table->timestamps();
            $table->softDeletes();
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
            $table->string('phone_normalized')->nullable();
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
            $table->string('recurring_frequency')->nullable();
            $table->decimal('recurring_amount', 15, 2)->nullable();
            $table->date('recurring_start_date')->nullable();
            $table->string('recurring_end_type')->nullable();
            $table->date('recurring_end_date')->nullable();
            $table->date('next_billing_date')->nullable();
            $table->unsignedInteger('billing_cycles')->nullable();
            $table->decimal('contract_value', 15, 2)->nullable();
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

        $organization = Organization::create([
            'name' => 'Regression CRM',
            'slug' => 'regression-crm',
            'is_active' => true,
        ]);
        $this->owner = User::forceCreate([
            'organization_id' => $organization->id,
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
            'role' => 'owner',
            'is_active' => true,
        ]);
        Subscription::create([
            'organization_id' => $organization->id,
            'user_id' => $this->owner->id,
            'plan' => 'enterprise',
            'status' => 'active',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->owner);
    }

    public function test_one_time_lead_discards_stale_recurring_values(): void
    {
        $response = $this->postJson('/api/leads', $this->leadPayload([
            'expected_value' => 55555,
            'recurring_frequency' => 'MONTHLY',
            'recurring_amount' => 9999,
            'recurring_start_date' => '',
            'recurring_end_type' => 'ONGOING',
            'recurring_end_date' => '2027-12-31',
            'next_billing_date' => '',
            'billing_cycles' => 12,
            'contract_value' => 119988,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.business_type', 'ONE_TIME')
            ->assertJsonPath('data.expected_value', 55555);

        $lead = Lead::firstOrFail();
        self::assertSame('INR', $lead->currency);
        self::assertNull($lead->recurring_frequency);
        self::assertNull($lead->recurring_amount);
        self::assertNull($lead->recurring_start_date);
        self::assertNull($lead->recurring_end_type);
        self::assertNull($lead->recurring_end_date);
        self::assertNull($lead->next_billing_date);
        self::assertNull($lead->billing_cycles);
        self::assertNull($lead->contract_value);
    }

    public function test_recurring_lead_accepts_valid_contract_fields(): void
    {
        $response = $this->postJson('/api/leads', $this->leadPayload([
            'business_type' => 'RECURRING',
            'recurring_frequency' => 'MONTHLY',
            'recurring_amount' => 10000,
            'recurring_start_date' => '2026-10-15',
            'recurring_end_type' => 'FIXED',
            'recurring_end_date' => '2027-09-15',
            'next_billing_date' => '2026-11-15',
            'billing_cycles' => 12,
            'contract_value' => 120000,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.business_type', 'RECURRING')
            ->assertJsonPath('data.recurring_start_date', '2026-10-15')
            ->assertJsonPath('data.billing_cycles', 12);
    }

    public function test_invalid_recurring_input_returns_validation_errors(): void
    {
        $response = $this->postJson('/api/leads', $this->leadPayload([
            'business_type' => 'RECURRING',
            'recurring_frequency' => 'MONTHLY',
            'recurring_amount' => 10000,
            'recurring_start_date' => '',
            'recurring_end_type' => 'FIXED',
            'recurring_end_date' => '',
            'billing_cycles' => 0,
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'recurring_start_date',
                'recurring_end_date',
                'billing_cycles',
            ]);
        self::assertSame(0, Lead::count());
    }

    public function test_update_from_recurring_to_one_time_clears_recurring_fields(): void
    {
        $lead = $this->createRecurringLead();

        $response = $this->putJson("/api/leads/{$lead->id}", [
            'business_type' => 'ONE_TIME',
            'expected_value' => 55555,
            'recurring_frequency' => 'MONTHLY',
            'recurring_amount' => 10000,
            'recurring_start_date' => '',
            'recurring_end_type' => 'ONGOING',
            'billing_cycles' => 12,
        ]);

        $response->assertOk()->assertJsonPath('data.business_type', 'ONE_TIME');

        $lead->refresh();
        self::assertSame(55555.0, (float) $lead->expected_value);
        self::assertNull($lead->recurring_frequency);
        self::assertNull($lead->recurring_amount);
        self::assertNull($lead->recurring_start_date);
        self::assertNull($lead->recurring_end_type);
        self::assertNull($lead->recurring_end_date);
        self::assertNull($lead->next_billing_date);
        self::assertNull($lead->billing_cycles);
        self::assertNull($lead->contract_value);
    }

    public function test_update_to_recurring_requires_complete_contract_fields(): void
    {
        $lead = Lead::create([
            ...$this->leadPayload(),
            'organization_id' => $this->owner->organization_id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->putJson("/api/leads/{$lead->id}", [
            'business_type' => 'RECURRING',
            'recurring_frequency' => 'MONTHLY',
            'recurring_amount' => 10000,
            'recurring_start_date' => '',
            'recurring_end_type' => 'ONGOING',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['recurring_start_date']);
        self::assertSame('ONE_TIME', $lead->fresh()->business_type);
    }

    public function test_update_from_one_time_to_recurring_accepts_complete_contract_fields(): void
    {
        $lead = Lead::create([
            ...$this->leadPayload(),
            'organization_id' => $this->owner->organization_id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->putJson("/api/leads/{$lead->id}", [
            'business_type' => 'RECURRING',
            'recurring_frequency' => 'QUARTERLY',
            'recurring_amount' => 30000,
            'recurring_start_date' => '2026-10-15',
            'recurring_end_type' => 'ONGOING',
            'recurring_end_date' => '',
            'next_billing_date' => '2027-01-15',
            'billing_cycles' => 4,
            'contract_value' => 120000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.business_type', 'RECURRING')
            ->assertJsonPath('data.recurring_frequency', 'QUARTERLY')
            ->assertJsonPath('data.recurring_start_date', '2026-10-15')
            ->assertJsonPath('data.recurring_end_date', null);

        $lead->refresh();
        self::assertSame('ONGOING', $lead->recurring_end_type);
        self::assertNull($lead->recurring_end_date);
        self::assertSame(4, $lead->billing_cycles);
    }

    /** @param array<string, mixed> $overrides */
    private function leadPayload(array $overrides = []): array
    {
        return array_merge([
            'client_type' => 'NEW',
            'business_type' => 'ONE_TIME',
            'market_type' => 'DOMESTIC',
            'first_name' => 'Mobile App Prospect',
            'source' => 'manual',
            'types' => 'mobile_app_development',
            'currency' => 'INR',
            'notes' => '',
        ], $overrides);
    }

    private function createRecurringLead(): Lead
    {
        return Lead::create([
            ...$this->leadPayload([
                'business_type' => 'RECURRING',
                'recurring_frequency' => 'MONTHLY',
                'recurring_amount' => 10000,
                'recurring_start_date' => '2026-10-15',
                'recurring_end_type' => 'FIXED',
                'recurring_end_date' => '2027-09-15',
                'next_billing_date' => '2026-11-15',
                'billing_cycles' => 12,
                'contract_value' => 120000,
            ]),
            'organization_id' => $this->owner->organization_id,
            'created_by' => $this->owner->id,
        ]);
    }
}
