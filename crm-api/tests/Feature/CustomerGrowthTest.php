<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CustomerGrowthSetting;
use App\Models\Deal;
use App\Models\GrowthRecommendation;
use App\Models\User;
use App\Services\CustomerGrowthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerGrowthTest extends TestCase
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
            $table->string('role')->default('employee');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->string('title');
            $table->decimal('value', 15, 2)->default(0);
            $table->string('currency')->default('INR');
            $table->string('status')->default('open');
            $table->string('service_type')->nullable();
            $table->date('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('deal_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('created_by');
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->timestamps();
        });
        Schema::create('recurring_businesses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('business_name');
            $table->string('service_type')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency')->default('INR');
            $table->string('frequency')->default('MONTHLY');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('billing_cycles')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
        });
        Schema::create('customer_growth_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->unique();
            $table->boolean('cross_sell_enabled')->default(true);
            $table->boolean('upsell_enabled')->default(true);
            $table->boolean('renewal_reminders_enabled')->default(true);
            $table->boolean('health_alerts_enabled')->default(true);
            $table->json('reminder_intervals')->nullable();
            $table->json('service_mappings')->nullable();
            $table->json('health_thresholds')->nullable();
            $table->json('notification_channels')->nullable();
            $table->string('default_assignment')->default('client_owner');
            $table->timestamps();
        });
        Schema::create('growth_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('source_deal_id')->nullable();
            $table->unsignedBigInteger('source_recurring_business_id')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('existing_service');
            $table->string('suggested_service');
            $table->string('recommendation_type');
            $table->text('reason');
            $table->decimal('potential_value', 15, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('estimate_source')->nullable();
            $table->string('priority')->default('medium');
            $table->date('suggested_follow_up_date')->nullable();
            $table->string('status')->default('new');
            $table->dateTime('snoozed_until')->nullable();
            $table->text('dismissal_reason')->nullable();
            $table->unsignedBigInteger('converted_deal_id')->nullable();
            $table->string('fingerprint');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'fingerprint']);
        });
    }

    public function test_recommendations_use_won_purchases_skip_owned_services_and_do_not_count_estimates_as_revenue(): void
    {
        $admin = User::forceCreate(['organization_id' => 1, 'name' => 'Admin', 'email' => 'growth-admin@example.test', 'password' => 'password', 'role' => 'admin']);
        $employee = User::forceCreate(['organization_id' => 1, 'name' => 'Rep', 'email' => 'growth-rep@example.test', 'password' => 'password', 'role' => 'employee']);
        $client = Client::create(['organization_id' => 1, 'created_by' => $admin->id, 'assigned_to' => $employee->id, 'first_name' => 'Acme']);
        $other = Client::create(['organization_id' => 1, 'created_by' => $admin->id, 'first_name' => 'Comparable']);
        Deal::create(['organization_id' => 1, 'client_id' => $client->id, 'assigned_to' => $employee->id, 'created_by' => $admin->id, 'title' => 'Website', 'value' => 100000, 'currency' => 'INR', 'status' => 'won', 'service_type' => 'Website Development']);
        Deal::create(['organization_id' => 1, 'client_id' => $other->id, 'created_by' => $admin->id, 'title' => 'SEO comparable', 'value' => 50000, 'currency' => 'INR', 'status' => 'won', 'service_type' => 'SEO']);
        CustomerGrowthSetting::create([
            'organization_id' => 1,
            'service_mappings' => [
                ['source' => 'Website Development', 'target' => 'SEO', 'type' => 'cross_sell', 'reason' => 'Configured mapping'],
                ['source' => 'Website Development', 'target' => 'Website Development', 'type' => 'upsell', 'reason' => 'Invalid already owned service'],
            ],
        ]);

        $service = app(CustomerGrowthService::class);
        self::assertSame(1, $service->refreshRecommendations(1));
        self::assertSame(0, $service->refreshRecommendations(1));
        $recommendation = GrowthRecommendation::sole();
        self::assertSame('SEO', $recommendation->suggested_service);
        self::assertSame(50000.0, (float) $recommendation->potential_value);
        self::assertStringContainsString('Comparable won deal', $recommendation->estimate_source);

        $employeeView = $service->overview($employee, []);
        self::assertSame(1, $employeeView['summary']['total']);
        self::assertCount(0, $employeeView['summary']['actual_won_value']);
    }
}
