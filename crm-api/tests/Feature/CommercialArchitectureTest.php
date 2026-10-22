<?php

namespace Tests\Feature;

use App\Http\Controllers\Calendar\CalendarController;
use App\Http\Controllers\Client\ClientController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Deal\DealController;
use App\Http\Controllers\RecurringBusiness\RecurringBusinessController;
use App\Http\Controllers\SalesTarget\SalesTargetController;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\RecurringBusiness;
use App\Models\SalesTarget;
use App\Models\User;
use App\Services\GeographicAnalyticsService;
use App\Services\RecurringRevenueService;
use App\Services\RevenueRecognitionService;
use App\Services\TargetProgressService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommercialArchitectureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('timezone')->nullable();
            $t->json('settings')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Organization::create(['name' => 'Acme', 'slug' => 'acme', 'timezone' => 'Asia/Kolkata']);
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password')->nullable();
            $t->string('role')->default('employee');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->string('name');
            $t->string('description')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('department_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('department_id');
            $t->unsignedBigInteger('user_id');
            $t->string('position')->nullable();
            $t->timestamps();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->string('first_name');
            $t->string('last_name')->nullable();
            $t->string('company')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('phone_normalized')->nullable();
            $t->string('job_title')->nullable();
            $t->string('website')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->string('country')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('client_id')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->string('first_name');
            $t->string('last_name')->nullable();
            $t->string('company')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('phone_normalized')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->string('country')->nullable();
            $t->string('status')->default('new');
            $t->string('client_type')->nullable();
            $t->string('business_type')->nullable();
            $t->string('market_type')->nullable();
            $t->string('types')->nullable();
            $t->text('notes')->nullable();
            $t->text('custom_fields')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('lead_timeline', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('lead_id');
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('description')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
        });
        Schema::create('deals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('client_id')->nullable();
            $t->unsignedBigInteger('lead_id')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('pipeline_id')->nullable();
            $t->unsignedBigInteger('stage_id')->nullable();
            $t->string('title');
            $t->decimal('value', 15, 2)->default(0);
            $t->string('currency')->default('INR');
            $t->string('status')->default('open');
            $t->string('business_type')->nullable();
            $t->string('market_type')->nullable();
            $t->string('service_type')->nullable();
            $t->date('closed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('deal_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('deal_id');
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('created_by');
            $t->decimal('amount', 15, 2);
            $t->date('payment_date');
            $t->string('payment_mode')->default('other');
            $t->string('txn_or_utr_number')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('activities', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id');
            $t->string('type');
            $t->boolean('is_done')->default(false);
            $t->dateTime('due_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('recurring_businesses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('client_id')->nullable();
            $t->unsignedBigInteger('lead_id')->nullable();
            $t->unsignedBigInteger('deal_id');
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('business_name');
            $t->decimal('amount', 15, 2);
            $t->string('currency')->default('INR');
            $t->string('frequency');
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->date('next_billing_date')->nullable();
            $t->unsignedInteger('billing_cycles')->nullable();
            $t->decimal('contract_value', 15, 2)->nullable();
            $t->string('status')->default('ACTIVE');
            $t->text('notes')->nullable();
            $t->dateTime('paused_at')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('sales_targets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('user_id');
            $t->string('target_type')->default('monthly');
            $t->decimal('target_amount', 15, 2)->default(0);
            $t->decimal('receivable_amount', 15, 2)->default(0);
            $t->decimal('received_amount', 15, 2)->nullable();
            $t->string('notes')->nullable();
            $t->date('period_start');
            $t->date('period_end')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
    }

    public function test_same_client_can_have_multiple_independent_leads_and_deals(): void
    {
        $client = Client::create(['organization_id' => 1, 'first_name' => 'ABC', 'company' => 'ABC Technologies']);
        $leadOne = Lead::create(['organization_id' => 1, 'client_id' => $client->id, 'created_by' => 1, 'first_name' => 'ABC', 'client_type' => 'NEW', 'business_type' => 'ONE_TIME']);
        $leadTwo = Lead::create(['organization_id' => 1, 'client_id' => $client->id, 'created_by' => 1, 'first_name' => 'ABC', 'client_type' => 'EXISTING', 'business_type' => 'RECURRING']);
        Deal::create(['organization_id' => 1, 'client_id' => $client->id, 'lead_id' => $leadOne->id, 'created_by' => 1, 'title' => 'Website', 'value' => 100000]);
        Deal::create(['organization_id' => 1, 'client_id' => $client->id, 'lead_id' => $leadTwo->id, 'created_by' => 1, 'title' => 'SEO', 'value' => 25000, 'business_type' => 'RECURRING']);
        self::assertCount(2, $client->fresh()->leads);
        self::assertCount(2, $client->fresh()->deals);
        self::assertSame(1, Client::count());
    }

    public function test_client_history_is_organization_wide_for_managers_and_assignment_scoped_for_employees(): void
    {
        $owner = User::forceCreate(['organization_id' => 1, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'owner']);
        $admin = User::forceCreate(['organization_id' => 1, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'admin']);
        $employeeA = User::forceCreate(['organization_id' => 1, 'name' => 'Employee A', 'email' => 'a@example.com', 'password' => 'password', 'role' => 'employee']);
        $employeeB = User::forceCreate(['organization_id' => 1, 'name' => 'Employee B', 'email' => 'b@example.com', 'password' => 'password', 'role' => 'employee']);
        $client = Client::create(['organization_id' => 1, 'created_by' => $owner->id, 'first_name' => 'ABC', 'company' => 'ABC Technologies']);

        $leadA = Lead::create(['organization_id' => 1, 'client_id' => $client->id, 'created_by' => $owner->id, 'assigned_to' => $employeeA->id, 'first_name' => 'ABC', 'client_type' => 'EXISTING', 'business_type' => 'ONE_TIME']);
        $leadB = Lead::create(['organization_id' => 1, 'client_id' => $client->id, 'created_by' => $owner->id, 'assigned_to' => $employeeB->id, 'first_name' => 'ABC', 'client_type' => 'EXISTING', 'business_type' => 'ONE_TIME']);
        Deal::create(['organization_id' => 1, 'client_id' => $client->id, 'lead_id' => $leadA->id, 'assigned_to' => $employeeA->id, 'created_by' => $owner->id, 'title' => 'Website', 'value' => 100000, 'status' => 'won', 'business_type' => 'ONE_TIME']);
        Deal::create(['organization_id' => 1, 'client_id' => $client->id, 'lead_id' => $leadB->id, 'assigned_to' => $employeeB->id, 'created_by' => $owner->id, 'title' => 'Mobile App', 'value' => 900000, 'status' => 'won', 'business_type' => 'ONE_TIME']);

        $employeePayload = $this->clientDetailsFor($employeeA, $client);
        self::assertCount(1, $employeePayload['leads']);
        self::assertCount(1, $employeePayload['deals']);
        self::assertSame($leadA->id, $employeePayload['leads'][0]['id']);
        self::assertSame(100000.0, (float) $employeePayload['revenue']['total_revenue']);

        foreach ([$owner, $admin] as $manager) {
            $managerPayload = $this->clientDetailsFor($manager, $client);
            self::assertCount(2, $managerPayload['leads']);
            self::assertCount(2, $managerPayload['deals']);
            self::assertSame(1000000.0, (float) $managerPayload['revenue']['total_revenue']);
        }
    }

    public function test_recurring_contract_is_linked_to_client_lead_and_deal(): void
    {
        $client = Client::create(['organization_id' => 1, 'first_name' => 'ABC']);
        $lead = Lead::create(['organization_id' => 1, 'client_id' => $client->id, 'created_by' => 1, 'first_name' => 'ABC']);
        $deal = Deal::create(['organization_id' => 1, 'client_id' => $client->id, 'lead_id' => $lead->id, 'created_by' => 1, 'title' => 'SEO', 'value' => 300000, 'business_type' => 'RECURRING']);
        $business = RecurringBusiness::create(['organization_id' => 1, 'client_id' => $client->id, 'lead_id' => $lead->id, 'deal_id' => $deal->id, 'business_name' => 'SEO', 'amount' => 25000, 'frequency' => 'MONTHLY', 'start_date' => '2027-01-01']);
        self::assertSame($client->id, $business->client->id);
        self::assertSame($lead->id, $business->lead->id);
        self::assertSame($deal->id, $business->deal->id);
    }

    public function test_main_revenue_does_not_count_future_recurring_contract_value(): void
    {
        Deal::create(['organization_id' => 1, 'created_by' => 1, 'title' => 'Website', 'value' => 100000, 'status' => 'won', 'business_type' => 'ONE_TIME', 'closed_at' => '2027-01-10']);
        $recurring = Deal::create(['organization_id' => 1, 'created_by' => 1, 'title' => 'SEO', 'value' => 300000, 'status' => 'open', 'business_type' => 'RECURRING']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $recurring->id, 'created_by' => 1, 'amount' => 25000, 'payment_date' => '2027-01-15']);
        $breakdown = (new RevenueRecognitionService(new RecurringRevenueService))->breakdown(1);
        self::assertSame(100000.0, $breakdown['one_time_revenue']);
        self::assertSame(25000.0, $breakdown['recurring_revenue']);
        self::assertSame(125000.0, $breakdown['total_revenue']);
    }

    public function test_recurring_outstanding_uses_due_cycles_not_contract_value(): void
    {
        $deal = Deal::create(['organization_id' => 1, 'created_by' => 1, 'title' => 'SEO', 'value' => 300000, 'business_type' => 'RECURRING']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $deal->id, 'created_by' => 1, 'amount' => 25000, 'payment_date' => '2027-01-01']);
        $business = RecurringBusiness::create(['organization_id' => 1, 'deal_id' => $deal->id, 'business_name' => 'SEO', 'amount' => 25000, 'frequency' => 'MONTHLY', 'start_date' => '2027-01-01', 'contract_value' => 300000]);
        $service = new RecurringRevenueService;
        self::assertSame(50000.0, $service->dueAmount($business, Carbon::parse('2027-02-15')));
        self::assertSame(25000.0, $service->collectedAmount($business));
    }

    public function test_soft_deleted_recurring_deal_keeps_history_without_breaking_dashboard_or_list(): void
    {
        Carbon::setTestNow('2027-04-15 12:00:00');
        $owner = User::forceCreate(['organization_id' => 1, 'name' => 'Owner', 'email' => 'recurring-owner@example.com', 'password' => 'password', 'role' => 'owner']);
        $employeeA = User::forceCreate(['organization_id' => 1, 'name' => 'Employee A', 'email' => 'recurring-a@example.com', 'password' => 'password', 'role' => 'employee']);
        $employeeB = User::forceCreate(['organization_id' => 1, 'name' => 'Employee B', 'email' => 'recurring-b@example.com', 'password' => 'password', 'role' => 'employee']);

        $validDeal = Deal::create(['organization_id' => 1, 'assigned_to' => $employeeA->id, 'created_by' => $owner->id, 'title' => 'Valid Retainer', 'value' => 25000, 'status' => 'open', 'business_type' => 'RECURRING']);
        $validBusiness = RecurringBusiness::create(['organization_id' => 1, 'deal_id' => $validDeal->id, 'assigned_to' => $employeeA->id, 'created_by' => $owner->id, 'business_name' => 'Valid Retainer', 'amount' => 25000, 'frequency' => 'MONTHLY', 'start_date' => '2027-01-01', 'status' => 'ACTIVE']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $validDeal->id, 'created_by' => $owner->id, 'amount' => 25000, 'payment_date' => '2027-04-01']);

        $deletedDeal = Deal::create(['organization_id' => 1, 'assigned_to' => $employeeB->id, 'created_by' => $owner->id, 'title' => 'Archived Test Retainer', 'value' => 305000, 'status' => 'open', 'business_type' => 'RECURRING']);
        $deletedBusiness = RecurringBusiness::create(['organization_id' => 1, 'deal_id' => $deletedDeal->id, 'assigned_to' => $employeeB->id, 'created_by' => $owner->id, 'business_name' => 'Archived Test Retainer', 'amount' => 61000, 'frequency' => 'MONTHLY', 'start_date' => '2026-12-01', 'status' => 'ACTIVE']);
        foreach ([60000, 60000, 60000, 60000, 65000] as $index => $amount) {
            DealPayment::create(['organization_id' => 1, 'deal_id' => $deletedDeal->id, 'created_by' => $owner->id, 'amount' => $amount, 'payment_date' => Carbon::parse('2026-12-01')->addMonths($index)->toDateString()]);
        }
        $deletedDeal->delete(); // Reproduce the legacy production state: contract still says ACTIVE.

        $newLead = Lead::create(['organization_id' => 1, 'created_by' => $owner->id, 'assigned_to' => $employeeA->id, 'first_name' => 'New', 'last_name' => 'Lead', 'status' => 'new']);
        $followupLead = Lead::create(['organization_id' => 1, 'created_by' => $owner->id, 'assigned_to' => $employeeA->id, 'first_name' => 'Due', 'last_name' => 'Today', 'status' => 'followup']);
        $meetingLead = Lead::create(['organization_id' => 1, 'created_by' => $owner->id, 'assigned_to' => $employeeA->id, 'first_name' => 'Overdue', 'last_name' => 'Meeting', 'status' => 'meeting']);
        Activity::create(['organization_id' => 1, 'assigned_to' => $employeeA->id, 'subject_type' => 'lead', 'subject_id' => $followupLead->id, 'type' => 'task', 'due_at' => now()->addHour(), 'is_done' => false]);
        Activity::create(['organization_id' => 1, 'assigned_to' => $employeeA->id, 'subject_type' => 'lead', 'subject_id' => $meetingLead->id, 'type' => 'meeting', 'due_at' => now()->subHour(), 'is_done' => false]);

        $wonDeal = Deal::create(['organization_id' => 1, 'assigned_to' => $employeeA->id, 'created_by' => $owner->id, 'title' => 'Won Website', 'value' => 100000, 'status' => 'won', 'business_type' => 'ONE_TIME', 'closed_at' => now()->toDateString()]);
        SalesTarget::create(['organization_id' => 1, 'user_id' => $employeeA->id, 'target_type' => 'monthly', 'target_amount' => 200000, 'receivable_amount' => 100000, 'period_start' => '2027-04-01', 'period_end' => '2027-04-30', 'created_by' => $owner->id]);

        $listResponse = $this->recurringBusinessIndexFor($owner);
        self::assertSame(200, $listResponse->getStatusCode());
        $list = $listResponse->getData(true)['data'];
        self::assertCount(2, $list['rows']);
        self::assertSame(1, $list['summary']['active_count']);
        self::assertSame(1, $list['summary']['cancelled_or_expired']);
        $archivedRow = collect($list['rows'])->firstWhere('id', $deletedBusiness->id);
        self::assertSame('CANCELLED', $archivedRow['status']);
        self::assertSame(305000.0, (float) $archivedRow['collected_revenue']);
        self::assertNull($archivedRow['next_billing_date']);
        self::assertNotNull($archivedRow['deal_deleted_at']);

        $revenue = app(RevenueRecognitionService::class);
        self::assertSame(330000.0, (float) $revenue->breakdown(1)['recurring_revenue']);
        self::assertSame(330000.0, (float) $revenue->collectionSummary(1, null, Carbon::now())['total_collected']);

        $dashboardRequest = Request::create('/api/dashboard', 'GET');
        $dashboardRequest->setUserResolver(fn () => $owner);
        $dashboard = app(DashboardController::class)->index($dashboardRequest);
        self::assertSame(200, $dashboard->getStatusCode());
        $dashboardData = $dashboard->getData(true)['data'];
        self::assertSame(3, $dashboardData['stats']['total_leads']);
        self::assertSame(1, $dashboardData['stats']['new_leads']);
        self::assertSame(1, $dashboardData['stats']['open_deals']);
        self::assertSame(1, $dashboardData['stats']['won_deals']);
        self::assertSame(25000.0, (float) $dashboardData['stats']['deal_value']);
        self::assertSame(1, $dashboardData['stats']['due_today_activities']);
        self::assertSame(1, $dashboardData['stats']['overdue_activities']);
        self::assertSame(330000.0, (float) $dashboardData['stats']['recurring_revenue']);
        self::assertSame(100000.0, (float) $dashboardData['stats']['one_time_revenue']);
        self::assertSame(430000.0, (float) $dashboardData['stats']['total_revenue']);
        self::assertNotEmpty($dashboardData['charts']['leads_trend']);
        self::assertNotEmpty($dashboardData['charts']['deals_by_stage']);
        self::assertNotEmpty($dashboardData['charts']['revenue_trend']);
        self::assertCount(3, $dashboardData['recent_leads']);
        self::assertCount(2, $dashboardData['recent_deals']);
        self::assertTrue($dashboardData['target_progress']['has_target']);
        self::assertSame(200000.0, (float) $dashboardData['performance']['target']['target_amount']);
        self::assertSame(125000.0, (float) $dashboardData['performance']['target']['achieved_amount']);
        self::assertSame(25000.0, (float) $dashboardData['performance']['target']['received_amount']);
        self::assertSame('01 Apr 2027 – 30 Apr 2027', $dashboardData['performance']['period_label']);

        $employeeDashboard = $this->dashboardFor($employeeA);
        self::assertSame(3, $employeeDashboard['stats']['total_leads']);
        self::assertSame(125000.0, (float) $employeeDashboard['stats']['total_revenue']);
        self::assertSame($employeeA->id, $employeeDashboard['performance']['selected_user_id']);

        $archivedEmployeeDashboard = $this->dashboardFor($employeeB);
        self::assertSame(305000.0, (float) $archivedEmployeeDashboard['stats']['recurring_revenue']);
        self::assertSame(0, $archivedEmployeeDashboard['stats']['open_deals']);
        self::assertCount(0, $archivedEmployeeDashboard['recent_deals']);

        self::assertSame([$validBusiness->id], collect($this->recurringBusinessIndexFor($employeeA)->getData(true)['data']['rows'])->pluck('id')->all());
        self::assertSame([$deletedBusiness->id], collect($this->recurringBusinessIndexFor($employeeB)->getData(true)['data']['rows'])->pluck('id')->all());

        $otherOrganization = Organization::create(['name' => 'Other Tenant', 'slug' => 'other-tenant', 'timezone' => 'Asia/Kolkata']);
        $otherOwner = User::forceCreate(['organization_id' => $otherOrganization->id, 'name' => 'Other Owner', 'email' => 'other-recurring-owner@example.com', 'password' => 'password', 'role' => 'owner']);
        self::assertCount(0, $this->recurringBusinessIndexFor($otherOwner)->getData(true)['data']['rows']);
        $emptyDashboard = $this->dashboardFor($otherOwner);
        self::assertSame(0, $emptyDashboard['stats']['total_leads']);
        self::assertSame(0.0, (float) $emptyDashboard['stats']['total_revenue']);
        self::assertSame([], $emptyDashboard['recent_leads']);
        self::assertSame([], $emptyDashboard['recent_deals']);
        self::assertFalse($emptyDashboard['target_progress']['has_target']);
        Carbon::setTestNow();
    }

    public function test_deleting_deal_cancels_future_recurring_billing_and_preserves_payments(): void
    {
        Carbon::setTestNow('2027-04-15 12:00:00');
        $admin = User::forceCreate(['organization_id' => 1, 'name' => 'Admin', 'email' => 'delete-recurring-admin@example.com', 'password' => 'password', 'role' => 'admin']);
        $deal = Deal::create(['organization_id' => 1, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'title' => 'Delete Retainer', 'value' => 120000, 'status' => 'open', 'business_type' => 'RECURRING']);
        $business = RecurringBusiness::create(['organization_id' => 1, 'deal_id' => $deal->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'business_name' => 'Delete Retainer', 'amount' => 10000, 'frequency' => 'MONTHLY', 'start_date' => '2027-01-01', 'next_billing_date' => '2027-05-01', 'status' => 'ACTIVE']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $deal->id, 'created_by' => $admin->id, 'amount' => 30000, 'payment_date' => '2027-03-01']);

        $request = Request::create("/api/deals/{$deal->id}", 'DELETE');
        $request->setUserResolver(fn () => $admin);
        $response = app(DealController::class)->destroy($request, $deal);
        $business->refresh()->load('deal.payments');

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull(Deal::withTrashed()->find($deal->id)?->deleted_at);
        self::assertSame('CANCELLED', $business->status);
        self::assertSame('2027-04-15', $business->end_date?->toDateString());
        self::assertNotNull($business->cancelled_at);
        self::assertNull($business->next_billing_date);
        self::assertCount(1, $business->deal?->payments ?? []);
        self::assertSame(30000.0, app(RecurringRevenueService::class)->collectedAmount($business));
        self::assertNull(app(RecurringRevenueService::class)->upcomingBillingDate($business, Carbon::now()));

        $reactivateRequest = Request::create("/api/recurring-businesses/{$business->id}", 'PUT', ['status' => 'ACTIVE']);
        $reactivateRequest->setUserResolver(fn () => $admin);
        try {
            app(RecurringBusinessController::class)->update($reactivateRequest, $business);
            self::fail('Archived deals must not be reactivated as recurring contracts.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('status', $exception->errors());
        }
        Carbon::setTestNow();
    }

    public function test_target_progress_uses_onboarded_business_and_actual_payments(): void
    {
        $employeeA = User::forceCreate(['organization_id' => 1, 'name' => 'Employee A', 'email' => 'target-a@example.com', 'password' => 'password', 'role' => 'employee']);
        $employeeB = User::forceCreate(['organization_id' => 1, 'name' => 'Employee B', 'email' => 'target-b@example.com', 'password' => 'password', 'role' => 'employee']);
        SalesTarget::create(['organization_id' => 1, 'user_id' => $employeeA->id, 'target_amount' => 300000, 'receivable_amount' => 200000, 'period_start' => '2027-01-01']);
        SalesTarget::create(['organization_id' => 1, 'user_id' => $employeeB->id, 'target_amount' => 200000, 'receivable_amount' => 100000, 'period_start' => '2027-01-01']);
        $dealA = Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $employeeA->id, 'created_by' => $employeeA->id, 'title' => 'Website', 'value' => 240000, 'status' => 'open', 'business_type' => 'ONE_TIME', 'created_at' => '2027-01-10', 'updated_at' => '2027-01-10']);
        $dealB = Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $employeeB->id, 'created_by' => $employeeB->id, 'title' => 'App', 'value' => 120000, 'status' => 'won', 'business_type' => 'ONE_TIME', 'closed_at' => '2027-01-20', 'created_at' => '2027-01-12', 'updated_at' => '2027-01-20']);
        Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $employeeA->id, 'created_by' => $employeeA->id, 'title' => 'Lost business', 'value' => 900000, 'status' => 'lost', 'created_at' => '2027-01-14', 'updated_at' => '2027-01-14']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $dealA->id, 'created_by' => $employeeA->id, 'amount' => 150000, 'payment_date' => '2027-01-15']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $dealB->id, 'created_by' => $employeeB->id, 'amount' => 60000, 'payment_date' => '2027-01-18']);

        $service = new TargetProgressService;
        $overall = $service->progress(1, null, '2027-01-01', '2027-01-31');
        self::assertSame(500000.0, $overall['target_amount']);
        self::assertSame(360000.0, $overall['achieved_amount']);
        self::assertSame(72.0, $overall['sales_percentage']);
        self::assertSame(300000.0, $overall['receivable_amount']);
        self::assertSame(210000.0, $overall['received_amount']);
        self::assertSame(70.0, $overall['collection_percentage']);

        $employee = $service->progress(1, $employeeA->id, '2027-01-01', '2027-01-31');
        self::assertSame(240000.0, $employee['achieved_amount']);
        self::assertSame(150000.0, $employee['received_amount']);
    }

    public function test_collection_summary_compares_equivalent_partial_month_periods_without_dividing_by_zero(): void
    {
        $employee = User::forceCreate(['organization_id' => 1, 'name' => 'Revenue Rep', 'email' => 'revenue-rep@example.com', 'password' => 'password', 'role' => 'employee']);
        $deal = Deal::create(['organization_id' => 1, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'Collection comparison', 'value' => 500000, 'status' => 'open']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $deal->id, 'created_by' => $employee->id, 'amount' => 100000, 'payment_date' => '2027-09-03']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $deal->id, 'created_by' => $employee->id, 'amount' => 150000, 'payment_date' => '2027-10-03']);

        $summary = app(RevenueRecognitionService::class)->collectionSummary(1, $employee->id, Carbon::parse('2027-10-05'));

        self::assertSame(150000.0, $summary['collected_this_month']);
        self::assertTrue($summary['comparisons']['collected_this_month']['available']);
        self::assertSame(50.0, $summary['comparisons']['collected_this_month']['change_percent']);
        self::assertSame('increase', $summary['comparisons']['collected_this_month']['direction']);
        self::assertFalse($summary['comparisons']['receivable']['available']);
    }

    public function test_collection_calendar_groups_historical_payment_dates_and_forces_employee_scope(): void
    {
        $admin = User::forceCreate(['organization_id' => 1, 'name' => 'Admin', 'email' => 'calendar-admin@example.com', 'password' => 'password', 'role' => 'admin']);
        $employeeA = User::forceCreate(['organization_id' => 1, 'name' => 'Employee A', 'email' => 'calendar-a@example.com', 'password' => 'password', 'role' => 'employee']);
        $employeeB = User::forceCreate(['organization_id' => 1, 'name' => 'Employee B', 'email' => 'calendar-b@example.com', 'password' => 'password', 'role' => 'employee']);
        $dealA = Deal::create(['organization_id' => 1, 'assigned_to' => $employeeA->id, 'created_by' => $admin->id, 'title' => 'A Deal', 'value' => 100000]);
        $dealB = Deal::create(['organization_id' => 1, 'assigned_to' => $employeeB->id, 'created_by' => $admin->id, 'title' => 'B Deal', 'value' => 100000]);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $dealA->id, 'created_by' => $admin->id, 'amount' => 15000, 'payment_date' => '2026-08-15']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $dealA->id, 'created_by' => $admin->id, 'amount' => 10000, 'payment_date' => '2026-08-15']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $dealB->id, 'created_by' => $admin->id, 'amount' => 25000, 'payment_date' => '2026-08-15']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $dealB->id, 'created_by' => $admin->id, 'amount' => 35000, 'payment_date' => '2026-09-09']);

        $adminPayload = $this->collectionCalendarFor($admin, '2026-08');
        self::assertSame(3, $adminPayload['summary']['transaction_count']);
        self::assertSame(1, $adminPayload['summary']['collection_days']);
        self::assertSame(50000.0, (float) $adminPayload['daily'][0]['totals'][0]['amount']);
        self::assertSame('2026-08-15', $adminPayload['daily'][0]['date']);

        $employeePayload = $this->collectionCalendarFor($employeeA, '2026-08', $employeeB->id);
        self::assertSame(2, $employeePayload['summary']['transaction_count']);
        self::assertSame(25000.0, (float) $employeePayload['daily'][0]['totals'][0]['amount']);
        self::assertSame('Employee A', $employeePayload['transactions'][0]['responsible_employee']);
    }

    public function test_geographic_analytics_uses_client_country_state_and_city_and_forces_employee_scope(): void
    {
        $admin = User::forceCreate(['organization_id' => 1, 'name' => 'Admin', 'email' => 'geo-admin@example.com', 'password' => 'password', 'role' => 'admin']);
        $employeeA = User::forceCreate(['organization_id' => 1, 'name' => 'Employee A', 'email' => 'geo-a@example.com', 'password' => 'password', 'role' => 'employee']);
        $employeeB = User::forceCreate(['organization_id' => 1, 'name' => 'Employee B', 'email' => 'geo-b@example.com', 'password' => 'password', 'role' => 'employee']);
        $usa = Client::create(['organization_id' => 1, 'created_by' => $admin->id, 'first_name' => 'Acme', 'company' => 'Acme US', 'country' => 'USA', 'state' => 'California', 'city' => 'San Francisco']);
        $india = Client::create(['organization_id' => 1, 'created_by' => $admin->id, 'first_name' => 'India Co', 'company' => 'India Co', 'country' => 'India', 'state' => 'Orissa', 'city' => 'Bhubaneswar']);
        $usLead = Lead::create(['organization_id' => 1, 'client_id' => $usa->id, 'created_by' => $admin->id, 'assigned_to' => $employeeA->id, 'first_name' => 'Acme', 'market_type' => 'INTERNATIONAL']);
        $indiaLead = Lead::create(['organization_id' => 1, 'client_id' => $india->id, 'created_by' => $admin->id, 'assigned_to' => $employeeB->id, 'first_name' => 'India Co', 'market_type' => 'DOMESTIC']);
        $usDeal = Deal::create(['organization_id' => 1, 'client_id' => $usa->id, 'lead_id' => $usLead->id, 'assigned_to' => $employeeA->id, 'created_by' => $admin->id, 'title' => 'US Website', 'value' => 1000, 'currency' => 'USD', 'status' => 'won', 'closed_at' => '2026-10-02']);
        Deal::create(['organization_id' => 1, 'client_id' => $usa->id, 'lead_id' => $usLead->id, 'assigned_to' => $employeeA->id, 'created_by' => $admin->id, 'title' => 'Historical US Retainer', 'value' => 400, 'currency' => 'USD', 'status' => 'open', 'created_at' => '2020-01-15 10:00:00']);
        $indiaDeal = Deal::create(['organization_id' => 1, 'client_id' => $india->id, 'lead_id' => $indiaLead->id, 'assigned_to' => $employeeB->id, 'created_by' => $admin->id, 'title' => 'India App', 'value' => 100000, 'currency' => 'INR', 'status' => 'open']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $usDeal->id, 'created_by' => $admin->id, 'amount' => 600, 'payment_date' => '2026-10-05']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $indiaDeal->id, 'created_by' => $admin->id, 'amount' => 25000, 'payment_date' => '2026-10-06']);

        $service = app(GeographicAnalyticsService::class);
        $international = $service->overview($admin, ['market' => 'international', 'rank_by' => 'collected_revenue']);
        self::assertSame('United States', $international['locations'][0]['name']);
        self::assertSame(2, $international['locations'][0]['total_deals']);
        self::assertSame(2, $international['summary']['total_deals']);
        self::assertSame(600.0, $international['summary']['collected_revenue'][0]['amount']);

        $domestic = $service->overview($admin, ['market' => 'domestic', 'state' => 'Odisha']);
        self::assertSame('city', $domestic['meta']['level']);
        self::assertSame('Bhubaneswar', $domestic['locations'][0]['name']);
        self::assertSame(25000.0, $domestic['locations'][0]['collected_revenue'][0]['amount']);

        $employeeScoped = $service->overview($employeeA, ['market' => 'domestic', 'assigned_to' => $employeeB->id]);
        self::assertSame(0, $employeeScoped['summary']['total_deals']);
    }

    public function test_custom_target_counts_only_records_inside_its_inclusive_date_range(): void
    {
        $employee = User::forceCreate(['organization_id' => 1, 'name' => 'Custom Period', 'email' => 'custom@example.com', 'password' => 'password', 'role' => 'employee']);
        $target = SalesTarget::create(['organization_id' => 1, 'user_id' => $employee->id, 'target_type' => 'custom', 'target_amount' => 200000, 'receivable_amount' => 100000, 'period_start' => '2027-01-10', 'period_end' => '2027-02-09']);
        $inside = Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'Inside', 'value' => 120000, 'status' => 'open', 'created_at' => '2027-01-10', 'updated_at' => '2027-01-10']);
        Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'Outside', 'value' => 500000, 'status' => 'open', 'created_at' => '2027-02-10', 'updated_at' => '2027-02-10']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $inside->id, 'created_by' => $employee->id, 'amount' => 75000, 'payment_date' => '2027-02-09']);
        DealPayment::create(['organization_id' => 1, 'deal_id' => $inside->id, 'created_by' => $employee->id, 'amount' => 25000, 'payment_date' => '2027-02-10']);

        $progress = (new TargetProgressService)->progressForTarget($target);
        self::assertSame(120000.0, $progress['achieved_amount']);
        self::assertSame(60.0, $progress['sales_percentage']);
        self::assertSame(75000.0, $progress['received_amount']);
        self::assertSame(75.0, $progress['collection_percentage']);
        self::assertSame('10 Jan 2027 – 09 Feb 2027', $progress['period_label']);
    }

    public function test_team_progress_respects_each_employee_target_dates_instead_of_one_shared_window(): void
    {
        $employeeA = User::forceCreate(['organization_id' => 1, 'name' => 'Early', 'email' => 'early@example.com', 'password' => 'password', 'role' => 'employee']);
        $employeeB = User::forceCreate(['organization_id' => 1, 'name' => 'Late', 'email' => 'late@example.com', 'password' => 'password', 'role' => 'employee']);
        SalesTarget::create(['organization_id' => 1, 'user_id' => $employeeA->id, 'target_type' => 'custom', 'target_amount' => 100000, 'receivable_amount' => 50000, 'period_start' => '2027-01-01', 'period_end' => '2027-01-31']);
        SalesTarget::create(['organization_id' => 1, 'user_id' => $employeeB->id, 'target_type' => 'custom', 'target_amount' => 100000, 'receivable_amount' => 50000, 'period_start' => '2027-01-16', 'period_end' => '2027-02-15']);
        Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $employeeA->id, 'created_by' => $employeeA->id, 'title' => 'Early Business', 'value' => 50000, 'status' => 'open', 'created_at' => '2027-01-10', 'updated_at' => '2027-01-10']);
        Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $employeeB->id, 'created_by' => $employeeB->id, 'title' => 'Late Business', 'value' => 75000, 'status' => 'open', 'created_at' => '2027-01-20', 'updated_at' => '2027-01-20']);
        $team = (new TargetProgressService)->activeTeamProgress(1, Carbon::parse('2027-01-20'));
        self::assertSame(200000.0, $team['target_amount']);
        self::assertSame(125000.0, $team['achieved_amount']);
        self::assertSame(62.5, $team['sales_percentage']);
        self::assertCount(2, $team['periods']);
    }

    public function test_sales_achievement_uses_inclusive_created_dates_and_matches_detail_rows(): void
    {
        $employee = User::forceCreate(['organization_id' => 1, 'name' => 'Boundary Rep', 'email' => 'boundary@example.com', 'password' => 'password', 'role' => 'employee']);
        $otherEmployee = User::forceCreate(['organization_id' => 1, 'name' => 'Other Rep', 'email' => 'other-boundary@example.com', 'password' => 'password', 'role' => 'employee']);
        $target = SalesTarget::create(['organization_id' => 1, 'user_id' => $employee->id, 'target_type' => 'custom', 'target_amount' => 1000, 'receivable_amount' => 500, 'period_start' => '2027-01-10', 'period_end' => '2027-01-31']);
        Organization::create(['name' => 'Other Org', 'slug' => 'other-org', 'timezone' => 'Asia/Kolkata']);

        Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'Before', 'value' => 9000, 'status' => 'open', 'created_at' => '2027-01-09 23:59:59', 'updated_at' => '2027-01-09 23:59:59']);
        $atStart = Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'At start', 'value' => 100, 'status' => 'open', 'created_at' => '2027-01-10 00:00:00', 'updated_at' => '2027-01-10 00:00:00']);
        $atEnd = Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'At end', 'value' => 200, 'status' => 'won', 'created_at' => '2027-01-31 23:59:59', 'updated_at' => '2027-01-31 23:59:59']);
        Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'After', 'value' => 9000, 'status' => 'open', 'created_at' => '2027-02-01 00:00:00', 'updated_at' => '2027-02-01 00:00:00']);
        Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'Lost', 'value' => 9000, 'status' => 'lost', 'created_at' => '2027-01-20 12:00:00', 'updated_at' => '2027-01-20 12:00:00']);
        Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $otherEmployee->id, 'created_by' => $otherEmployee->id, 'title' => 'Other employee', 'value' => 9000, 'status' => 'open', 'created_at' => '2027-01-20 12:00:00', 'updated_at' => '2027-01-20 12:00:00']);
        Deal::forceCreate(['organization_id' => 2, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'Other organization', 'value' => 9000, 'status' => 'open', 'created_at' => '2027-01-20 12:00:00', 'updated_at' => '2027-01-20 12:00:00']);

        $service = new TargetProgressService;
        $progress = $service->progressForTarget($target, Carbon::parse('2027-01-20'));
        $expectedIds = [$atStart->id, $atEnd->id];
        $rangeIds = $service->salesDetailsQuery(1, $employee->id, '2027-01-10', '2027-01-31')->orderBy('id')->pluck('id')->all();
        $activeIds = $service->activeSalesDetailsQuery(1, $employee->id, Carbon::parse('2027-01-20'))->orderBy('id')->pluck('id')->all();

        self::assertSame(300.0, $progress['achieved_amount']);
        self::assertSame($expectedIds, $rangeIds);
        self::assertSame($expectedIds, $activeIds);
        self::assertSame($progress['achieved_amount'], (float) Deal::query()->whereIn('id', $activeIds)->sum('value'));
    }

    public function test_sales_achievement_converts_business_day_bounds_to_storage_timezone(): void
    {
        $organization = Organization::create(['name' => 'New York Org', 'slug' => 'new-york-org', 'timezone' => 'America/New_York']);
        $employee = User::forceCreate(['organization_id' => $organization->id, 'name' => 'New York Rep', 'email' => 'new-york@example.com', 'password' => 'password', 'role' => 'employee']);

        Deal::forceCreate(['organization_id' => $organization->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'Before local day', 'value' => 9000, 'status' => 'open', 'created_at' => '2027-01-10 10:29:59', 'updated_at' => '2027-01-10 10:29:59']);
        $insideStart = Deal::forceCreate(['organization_id' => $organization->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'Local day start', 'value' => 100, 'status' => 'open', 'created_at' => '2027-01-10 10:30:00', 'updated_at' => '2027-01-10 10:30:00']);
        $insideEnd = Deal::forceCreate(['organization_id' => $organization->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'Local day end', 'value' => 200, 'status' => 'open', 'created_at' => '2027-01-11 10:29:59', 'updated_at' => '2027-01-11 10:29:59']);
        Deal::forceCreate(['organization_id' => $organization->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'After local day', 'value' => 9000, 'status' => 'open', 'created_at' => '2027-01-11 10:30:00', 'updated_at' => '2027-01-11 10:30:00']);

        $service = new TargetProgressService;

        self::assertSame(300.0, $service->achieved($organization->id, $employee->id, '2027-01-10', '2027-01-10'));
        self::assertSame([$insideStart->id, $insideEnd->id], $service->salesDetailsQuery($organization->id, $employee->id, '2027-01-10', '2027-01-10')->orderBy('id')->pluck('id')->all());
    }

    public function test_overlapping_legacy_targets_choose_one_active_period_per_employee_everywhere(): void
    {
        Carbon::setTestNow('2027-01-20 10:00:00');
        $owner = User::forceCreate(['organization_id' => 1, 'name' => 'Target Owner', 'email' => 'target-owner@example.com', 'password' => 'password', 'role' => 'owner']);
        $employee = User::forceCreate(['organization_id' => 1, 'name' => 'Overlap Rep', 'email' => 'overlap@example.com', 'password' => 'password', 'role' => 'employee']);
        SalesTarget::create(['organization_id' => 1, 'user_id' => $employee->id, 'target_type' => 'monthly', 'target_amount' => 1000, 'receivable_amount' => 500, 'period_start' => '2027-01-01', 'period_end' => '2027-01-31']);
        $selected = SalesTarget::create(['organization_id' => 1, 'user_id' => $employee->id, 'target_type' => 'custom', 'target_amount' => 2000, 'receivable_amount' => 1000, 'period_start' => '2027-01-10', 'period_end' => '2027-02-09']);
        $deal = Deal::forceCreate(['organization_id' => 1, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'title' => 'Count once', 'value' => 500, 'status' => 'open', 'created_at' => '2027-01-15 12:00:00', 'updated_at' => '2027-01-15 12:00:00']);

        $service = new TargetProgressService;
        $team = $service->activeTeamProgress(1, Carbon::now());
        self::assertSame($selected->id, $service->activeTarget(1, $employee->id, Carbon::now())?->id);
        self::assertSame([$selected->id], $service->targetsForDate(1, Carbon::now())->pluck('id')->all());
        self::assertSame(1, $team['target_count']);
        self::assertSame(500.0, $team['achieved_amount']);
        self::assertSame([$deal->id], $service->activeSalesDetailsQuery(1, null, Carbon::now())->pluck('id')->all());

        $request = Request::create('/api/sales-targets/leaderboard', 'GET');
        $request->setUserResolver(fn () => $owner);
        $rows = app(SalesTargetController::class)->leaderboard($request)->getData(true)['data']['rows'];
        self::assertCount(1, $rows);
        self::assertSame($selected->id, $rows[0]['target_id']);
        Carbon::setTestNow();
    }

    public function test_leaderboard_settings_limit_rows_to_selected_members(): void
    {
        Carbon::setTestNow('2027-01-20 10:00:00');
        $owner = User::forceCreate(['organization_id' => 1, 'name' => 'Owner', 'email' => 'leaderboard-owner@example.com', 'password' => 'password', 'role' => 'owner']);
        $selected = User::forceCreate(['organization_id' => 1, 'name' => 'Selected', 'email' => 'selected@example.com', 'password' => 'password', 'role' => 'employee']);
        $hidden = User::forceCreate(['organization_id' => 1, 'name' => 'Hidden', 'email' => 'hidden@example.com', 'password' => 'password', 'role' => 'employee']);
        foreach ([$selected, $hidden] as $member) {
            SalesTarget::create(['organization_id' => 1, 'user_id' => $member->id, 'target_type' => 'monthly', 'target_amount' => 100000, 'receivable_amount' => 50000, 'period_start' => '2027-01-01', 'period_end' => '2027-01-31']);
        }

        $controller = app(SalesTargetController::class);
        $save = Request::create('/api/sales-targets/leaderboard-settings', 'PUT', [
            'visibility' => 'everyone',
            'data_visibility' => 'names_percentages',
            'sales_weight' => 60,
            'collection_weight' => 40,
            'selected_user_ids' => [$selected->id],
        ]);
        $save->setUserResolver(fn () => $owner);
        $controller->updateLeaderboardSettings($save);

        $request = Request::create('/api/sales-targets/leaderboard', 'GET');
        $request->setUserResolver(fn () => $owner->fresh());
        $payload = $controller->leaderboard($request)->getData(true)['data'];

        self::assertSame([$selected->id], $payload['settings']['selected_user_ids']);
        self::assertSame([$selected->id], collect($payload['rows'])->pluck('user_id')->all());
        self::assertFalse(collect($payload['rows'])->pluck('user_id')->contains($hidden->id));
        Carbon::setTestNow();
    }

    /** @return array<string, mixed> */
    private function clientDetailsFor(User $user, Client $client): array
    {
        $request = Request::create("/api/clients/{$client->id}", 'GET');
        $request->setUserResolver(fn () => $user);

        $response = app(ClientController::class)->show($request, $client->fresh());

        return $response->getData(true)['data'];
    }

    private function recurringBusinessIndexFor(User $user): JsonResponse
    {
        $request = Request::create('/api/recurring-businesses', 'GET');
        $request->setUserResolver(fn () => $user);

        return app(RecurringBusinessController::class)->index($request);
    }

    /** @return array<string, mixed> */
    private function dashboardFor(User $user): array
    {
        $request = Request::create('/api/dashboard', 'GET');
        $request->setUserResolver(fn () => $user);

        return app(DashboardController::class)->index($request)->getData(true)['data'];
    }

    /** @return array<string, mixed> */
    private function collectionCalendarFor(User $user, string $month, ?int $assignedTo = null): array
    {
        $parameters = ['month' => $month];
        if ($assignedTo) {
            $parameters['assigned_to'] = $assignedTo;
        }
        $request = Request::create('/api/calendar/collections', 'GET', $parameters);
        $request->setUserResolver(fn () => $user);

        return app(CalendarController::class)->collections($request)->getData(true)['data'];
    }
}
