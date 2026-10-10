<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('source_deal_id')->nullable()->index();
            $table->unsignedBigInteger('source_recurring_business_id')->nullable()->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->string('existing_service');
            $table->string('suggested_service');
            $table->string('recommendation_type', 16);
            $table->text('reason');
            $table->decimal('potential_value', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('estimate_source')->nullable();
            $table->string('priority', 16)->default('medium');
            $table->date('suggested_follow_up_date')->nullable();
            $table->string('status', 24)->default('new');
            $table->dateTime('snoozed_until')->nullable();
            $table->text('dismissal_reason')->nullable();
            $table->unsignedBigInteger('converted_deal_id')->nullable()->index();
            $table->string('fingerprint', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'fingerprint']);
        });

        Schema::create('retention_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('recurring_business_id')->nullable()->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->string('task_type', 32);
            $table->string('status', 24)->default('open');
            $table->date('due_date')->nullable();
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('fingerprint', 64);
            $table->timestamps();
            $table->unique(['organization_id', 'fingerprint']);
        });

        Schema::create('customer_growth_notification_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('deduplication_key');
            $table->string('condition_hash', 64);
            $table->dateTime('notified_at');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'deduplication_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_growth_notification_keys');
        Schema::dropIfExists('retention_tasks');
        Schema::dropIfExists('growth_recommendations');
        Schema::dropIfExists('customer_growth_settings');
    }
};
