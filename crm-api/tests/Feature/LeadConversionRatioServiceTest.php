<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadTimeline;
use App\Models\Organization;
use App\Models\SalesTarget;
use App\Models\User;
use App\Services\LeadConversionRatioService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeadConversionRatioServiceTest extends TestCase
{
    private LeadConversionRatioService $service;

    private Organization $organization;

    private User $owner;

    private User $firstEmployee;

    private User $secondEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('Asia/Kolkata');
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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('first_name')->default('Lead');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('lead_timeline', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('target_type')->default('custom');
            $table->decimal('target_amount', 15, 2)->default(0);
            $table->decimal('receivable_amount', 15, 2)->default(0);
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamps();
        });

        $this->organization = Organization::create([
            'name' => 'Conversion Test', 'slug' => 'conversion-test',
            'timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);
        $this->owner = $this->user('Owner', 'owner@example.test', 'owner');
        $this->firstEmployee = $this->user('First Employee', 'first@example.test');
        $this->secondEmployee = $this->user('Second Employee', 'second@example.test');
        $this->service = app(LeadConversionRatioService::class);
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_current_month_counts_unique_assigned_and_converted_leads_at_inclusive_boundaries(): void
    {
        // Test timestamps are stored in the application's configured storage timezone
        // (Asia/Kolkata), so use the actual inclusive local-period boundaries.
        $lead = $this->lead($this->firstEmployee, '2026-10-01 00:00:00');
        $this->timeline($lead, 'deal_created', '2026-10-31 23:59:59');
        DB::table('deals')->insert([
            ['organization_id' => $this->organization->id, 'lead_id' => $lead->id, 'created_at' => '2026-10-10 00:00:00', 'updated_at' => '2026-10-10 00:00:00'],
            ['organization_id' => $this->organization->id, 'lead_id' => $lead->id, 'created_at' => '2026-10-11 00:00:00', 'updated_at' => '2026-10-11 00:00:00'],
        ]);
        DB::table('leads')->insert([
            'organization_id' => $this->organization->id + 999,
            'assigned_to' => $this->firstEmployee->id,
            'first_name' => 'Other tenant',
            'created_at' => '2026-10-05 00:00:00',
            'updated_at' => '2026-10-05 00:00:00',
        ]);

        $period = $this->service->resolvePeriod($this->owner, ['conversion_period' => 'current_month']);
        $result = $this->service->calculate($this->organization->id, collect([$this->firstEmployee]), $period);

        self::assertSame('October 2026', $period['label']);
        self::assertSame(1, $result['members'][$this->firstEmployee->id]['assigned_leads']);
        self::assertSame(1, $result['members'][$this->firstEmployee->id]['converted_leads']);
        self::assertSame(100.0, $result['members'][$this->firstEmployee->id]['ratio']);
    }

    public function test_reassignment_credits_the_employee_who_received_and_owned_the_lead_at_conversion(): void
    {
        $lead = $this->lead($this->firstEmployee, '2026-09-20 04:30:00');
        $this->timeline($lead, 'assigned', '2026-10-10 04:30:00', [
            'assigned_to' => ['from' => $this->firstEmployee->id, 'to' => $this->secondEmployee->id],
        ]);
        $this->timeline($lead, 'deal_created', '2026-10-20 04:30:00');

        $period = $this->service->resolvePeriod($this->owner, [
            'conversion_period' => 'custom', 'conversion_start' => '2026-10-01', 'conversion_end' => '2026-10-31',
        ]);
        $result = $this->service->calculate($this->organization->id, collect([$this->firstEmployee, $this->secondEmployee]), $period);

        self::assertSame(0, $result['members'][$this->firstEmployee->id]['assigned_leads']);
        self::assertSame(1, $result['members'][$this->secondEmployee->id]['assigned_leads']);
        self::assertSame(1, $result['members'][$this->secondEmployee->id]['converted_leads']);
        self::assertSame(1, $result['team']['assigned_leads']);
    }

    public function test_target_period_and_employee_visible_periods_use_existing_targets(): void
    {
        $first = SalesTarget::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->firstEmployee->id,
            'target_type' => 'custom', 'period_start' => '2026-09-15', 'period_end' => '2026-10-14',
        ]);
        $second = SalesTarget::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->secondEmployee->id,
            'target_type' => 'custom', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
        ]);

        $period = $this->service->resolvePeriod($this->owner, [
            'conversion_period' => 'target', 'conversion_target_id' => $first->id,
        ]);

        self::assertSame('2026-09-15', $period['start_date']);
        self::assertSame('2026-10-14', $period['end_date']);
        self::assertCount(2, $this->service->targetPeriods($this->owner));
        self::assertCount(1, $this->service->targetPeriods($this->firstEmployee));

        $this->expectException(ValidationException::class);
        $this->service->resolvePeriod($this->firstEmployee, [
            'conversion_period' => 'target', 'conversion_target_id' => $second->id,
        ]);
    }

    public function test_invalid_custom_range_is_rejected_and_zero_denominator_is_safe(): void
    {
        try {
            $this->service->resolvePeriod($this->owner, [
                'conversion_period' => 'custom', 'conversion_start' => '2026-10-20', 'conversion_end' => '2026-10-10',
            ]);
            self::fail('Expected invalid range validation.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('conversion_end', $exception->errors());
        }

        $period = $this->service->resolvePeriod($this->owner, ['conversion_period' => 'month', 'conversion_month' => '2025-01']);
        $result = $this->service->calculate($this->organization->id, collect([$this->firstEmployee]), $period);
        self::assertSame(0, $result['team']['assigned_leads']);
        self::assertSame(0.0, $result['team']['ratio']);
    }

    private function user(string $name, string $email, string $role = 'employee'): User
    {
        return User::forceCreate([
            'organization_id' => $this->organization->id, 'name' => $name, 'email' => $email,
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function lead(User $assignee, string $createdAt): Lead
    {
        return Lead::forceCreate([
            'organization_id' => $this->organization->id, 'assigned_to' => $assignee->id,
            'first_name' => 'Prospect', 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    /** @param array<string, mixed> $meta */
    private function timeline(Lead $lead, string $action, string $createdAt, array $meta = []): LeadTimeline
    {
        return LeadTimeline::forceCreate([
            'lead_id' => $lead->id, 'organization_id' => $this->organization->id,
            'action' => $action, 'description' => $action, 'meta' => $meta ?: null,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }
}
