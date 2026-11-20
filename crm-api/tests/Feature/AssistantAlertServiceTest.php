<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\AssistantAlertService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssistantAlertServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->nullable();
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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->boolean('is_done')->default(false);
            $table->string('priority')->default('normal');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_employee_only_receives_important_alerts_for_their_own_leads_and_legacy_subject_types(): void
    {
        $organization = Organization::create(['name' => 'Alpha', 'slug' => 'alpha', 'timezone' => 'Asia/Kolkata']);
        $employee = User::create(['organization_id' => $organization->id, 'name' => 'Employee', 'email' => 'employee@example.test', 'role' => 'employee']);
        $other = User::create(['organization_id' => $organization->id, 'name' => 'Other', 'email' => 'other@example.test', 'role' => 'employee']);
        $mine = Lead::create(['organization_id' => $organization->id, 'assigned_to' => $employee->id, 'first_name' => 'My', 'last_name' => 'Lead']);
        $theirs = Lead::create(['organization_id' => $organization->id, 'assigned_to' => $other->id, 'first_name' => 'Other', 'last_name' => 'Lead']);
        Activity::create(['organization_id' => $organization->id, 'subject_type' => 'lead', 'subject_id' => $mine->id, 'type' => 'task', 'title' => 'Call back', 'due_at' => now()->subHour(), 'priority' => 'high']);
        Activity::create(['organization_id' => $organization->id, 'subject_type' => Lead::class, 'subject_id' => $theirs->id, 'type' => 'task', 'title' => 'Private follow-up', 'due_at' => now()->subHour(), 'priority' => 'high']);

        $alerts = app(AssistantAlertService::class)->importantAlerts($employee->fresh());

        $this->assertCount(1, $alerts);
        $this->assertSame('My Lead', $alerts[0]['lead_name']);
        $this->assertSame('overdue_followup', $alerts[0]['type']);
    }

    public function test_completed_and_ordinary_future_activities_do_not_trigger_buddy(): void
    {
        $organization = Organization::create(['name' => 'Beta', 'slug' => 'beta', 'timezone' => 'Asia/Kolkata']);
        $employee = User::create(['organization_id' => $organization->id, 'name' => 'Employee', 'email' => 'beta@example.test', 'role' => 'employee']);
        $lead = Lead::create(['organization_id' => $organization->id, 'assigned_to' => $employee->id, 'first_name' => 'Quiet']);
        Activity::create(['organization_id' => $organization->id, 'subject_type' => Lead::class, 'subject_id' => $lead->id, 'type' => 'task', 'title' => 'Done', 'due_at' => now()->subHour(), 'is_done' => true]);
        Activity::create(['organization_id' => $organization->id, 'subject_type' => Lead::class, 'subject_id' => $lead->id, 'type' => 'meeting', 'title' => 'Later', 'due_at' => now()->addHours(4), 'priority' => 'normal']);

        $this->assertSame([], app(AssistantAlertService::class)->importantAlerts($employee->fresh()));
    }

    public function test_high_priority_non_schedule_activity_is_actionable_without_changing_it(): void
    {
        $organization = Organization::create(['name' => 'Gamma', 'slug' => 'gamma', 'timezone' => 'Asia/Kolkata']);
        $employee = User::create(['organization_id' => $organization->id, 'name' => 'Employee', 'email' => 'gamma@example.test', 'role' => 'employee']);
        $lead = Lead::create(['organization_id' => $organization->id, 'assigned_to' => $employee->id, 'first_name' => 'Priority']);
        $activity = Activity::create(['organization_id' => $organization->id, 'subject_type' => Lead::class, 'subject_id' => $lead->id, 'type' => 'call', 'title' => 'Urgent call', 'priority' => 'high']);

        $alerts = app(AssistantAlertService::class)->importantAlerts($employee->fresh());

        $this->assertCount(1, $alerts);
        $this->assertSame('urgent_activity', $alerts[0]['type']);
        $this->assertSame('/activities', $alerts[0]['navigation']);
        $this->assertFalse($activity->fresh()->is_done);
    }

    public function test_only_visible_tracked_activities_are_reported_as_confirmed_completions(): void
    {
        $org = Organization::create(['name' => 'Delta', 'slug' => 'delta', 'timezone' => 'Asia/Kolkata']);
        $employee = User::create(['organization_id' => $org->id, 'name' => 'Employee', 'email' => 'delta@example.test', 'role' => 'employee']);
        $own = Lead::create(['organization_id' => $org->id, 'assigned_to' => $employee->id, 'first_name' => 'Own']);
        $unassigned = Lead::create(['organization_id' => $org->id, 'first_name' => 'Unassigned']);
        $create = fn (Lead $lead, bool $done) => Activity::create([
            'organization_id' => $org->id, 'subject_type' => 'lead', 'subject_id' => $lead->id,
            'type' => 'task', 'title' => 'Call back', 'is_done' => $done,
        ])->id;

        $complete = $create($own, true);
        $open = $create($own, false);
        $private = $create($unassigned, true);
        $create($own, true);

        $service = app(AssistantAlertService::class);
        $this->assertSame([$complete], $service->completedActivityIds($employee, [$complete, $open, $private]));
        $this->assertSame([], $service->completedActivityIds($employee, []));
    }
}
