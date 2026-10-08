<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_normalized', 40)->nullable();
            $table->string('job_title')->nullable();
            $table->string('website', 500)->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'company']);
            $table->index(['organization_id', 'email']);
            $table->index(['organization_id', 'phone_normalized']);
            $table->index(['organization_id', 'assigned_to']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('organization_id')->constrained('clients')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('assigned_to')->constrained('departments')->nullOnDelete();
            $table->string('client_type', 20)->nullable()->after('lead_date');
            $table->string('business_type', 20)->nullable()->after('client_type');
            $table->string('market_type', 20)->nullable()->after('business_type');
            $table->string('state')->nullable()->after('city');
            $table->decimal('expected_value', 15, 2)->nullable()->after('score');
            $table->string('currency', 3)->default('INR')->after('expected_value');
            $table->string('recurring_frequency', 20)->nullable()->after('currency');
            $table->decimal('recurring_amount', 15, 2)->nullable()->after('recurring_frequency');
            $table->date('recurring_start_date')->nullable()->after('recurring_amount');
            $table->string('recurring_end_type', 20)->nullable()->after('recurring_start_date');
            $table->date('recurring_end_date')->nullable()->after('recurring_end_type');
            $table->date('next_billing_date')->nullable()->after('recurring_end_date');
            $table->unsignedInteger('billing_cycles')->nullable()->after('next_billing_date');
            $table->decimal('contract_value', 15, 2)->nullable()->after('billing_cycles');

            $table->index(['organization_id', 'client_type']);
            $table->index(['organization_id', 'business_type']);
            $table->index(['organization_id', 'market_type']);
            $table->index(['organization_id', 'client_id']);
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('organization_id')->constrained('clients')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('assigned_to')->constrained('departments')->nullOnDelete();
            $table->string('client_type', 20)->nullable()->after('title');
            $table->string('business_type', 20)->nullable()->after('client_type');
            $table->string('market_type', 20)->nullable()->after('business_type');
            $table->string('service_type')->nullable()->after('market_type');
            $table->string('recurring_frequency', 20)->nullable()->after('currency');
            $table->decimal('recurring_amount', 15, 2)->nullable()->after('recurring_frequency');
            $table->date('recurring_start_date')->nullable()->after('recurring_amount');
            $table->date('recurring_end_date')->nullable()->after('recurring_start_date');
            $table->date('next_billing_date')->nullable()->after('recurring_end_date');
            $table->unsignedInteger('billing_cycles')->nullable()->after('next_billing_date');
            $table->decimal('contract_value', 15, 2)->nullable()->after('billing_cycles');

            $table->index(['organization_id', 'business_type', 'status']);
            $table->index(['organization_id', 'market_type']);
            $table->index(['organization_id', 'client_id']);
        });

        Schema::create('recurring_businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('business_name');
            $table->string('service_type')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('frequency', 20);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_billing_date')->nullable();
            $table->unsignedInteger('billing_cycles')->nullable();
            $table->decimal('contract_value', 15, 2)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique('deal_id');
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'next_billing_date']);
            $table->index(['organization_id', 'assigned_to']);
            $table->index(['organization_id', 'client_id']);
        });

        // Existing CRM behavior represented one-time business. Keep all historic
        // records valid and create one canonical Client for every lead that already
        // became commercial history. No Lead or Deal is deleted or merged.
        DB::table('leads')->whereNull('business_type')->update(['business_type' => 'ONE_TIME']);
        DB::table('leads')->whereNull('client_type')->update(['client_type' => 'NEW']);
        DB::table('deals')->whereNull('business_type')->update(['business_type' => 'ONE_TIME']);

        DB::table('leads')
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->where('status', 'converted')
                    ->orWhereExists(function ($sub) {
                        $sub->selectRaw('1')->from('deals')->whereColumn('deals.lead_id', 'leads.id');
                    });
            })
            ->orderBy('id')
            ->chunkById(200, function ($leads) {
                foreach ($leads as $lead) {
                    $clientId = DB::table('clients')->insertGetId([
                        'organization_id' => $lead->organization_id,
                        'created_by' => $lead->created_by,
                        'assigned_to' => $lead->assigned_to,
                        'first_name' => $lead->first_name,
                        'last_name' => $lead->last_name,
                        'company' => $lead->company,
                        'email' => $lead->email,
                        'phone' => $lead->phone,
                        'phone_normalized' => $lead->phone_normalized,
                        'job_title' => $lead->job_title,
                        'website' => $lead->website,
                        'city' => $lead->city,
                        'state' => $lead->state,
                        'country' => $lead->country,
                        'created_at' => $lead->created_at,
                        'updated_at' => now(),
                    ]);

                    DB::table('leads')->where('id', $lead->id)->update(['client_id' => $clientId]);
                    DB::table('deals')->where('lead_id', $lead->id)->update([
                        'client_id' => $clientId,
                        'client_type' => 'NEW',
                        'business_type' => 'ONE_TIME',
                        'market_type' => $lead->market_type,
                        'service_type' => $lead->types,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_businesses');

        Schema::table('deals', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropForeign(['department_id']);
            $table->dropColumn([
                'client_id', 'department_id', 'client_type', 'business_type', 'market_type', 'service_type',
                'recurring_frequency', 'recurring_amount', 'recurring_start_date', 'recurring_end_date',
                'next_billing_date', 'billing_cycles', 'contract_value',
            ]);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropForeign(['department_id']);
            $table->dropColumn([
                'client_id', 'department_id', 'client_type', 'business_type', 'market_type', 'state',
                'expected_value', 'currency', 'recurring_frequency', 'recurring_amount',
                'recurring_start_date', 'recurring_end_type', 'recurring_end_date', 'next_billing_date',
                'billing_cycles', 'contract_value',
            ]);
        });

        Schema::dropIfExists('clients');
    }
};
