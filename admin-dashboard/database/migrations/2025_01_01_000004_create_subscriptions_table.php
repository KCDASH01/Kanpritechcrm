<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // subscription owner

            // Plan
            $table->enum('plan', ['free', 'business'])->default('free');

            // Source: 'internal' = paid inside CRM, 'external' = synced from SSO app
            $table->enum('subscription_source', ['internal', 'external'])->default('internal');

            // Dates
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();    // null = indefinite (free plan)

            // Status
            $table->boolean('is_active')->default(true);
            $table->enum('status', ['active', 'expired', 'cancelled', 'trialing'])->default('active');

            // Payment (internal only — mocked now)
            $table->string('gateway')->nullable();             // 'stripe', 'razorpay', etc.
            $table->string('gateway_subscription_id')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();

            // External sync metadata
            $table->json('external_metadata')->nullable();     // raw payload from SSO

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
