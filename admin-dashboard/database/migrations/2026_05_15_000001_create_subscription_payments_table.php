<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('type', ['plan_upgrade', 'seat_purchase']);

            $table->string('gateway', 50)->default('razorpay');
            $table->string('gateway_order_id', 191)->nullable()->index();   // Razorpay order_id
            $table->string('gateway_payment_id', 191)->nullable()->index(); // Razorpay payment_id (after verify)

            $table->tinyInteger('quantity')->unsigned()->nullable(); // seats purchased; null for plan upgrades

            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('INR');

            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');

            $table->date('valid_until')->nullable(); // subscription end_date at time of purchase

            $table->string('description');           // "Business Plan Upgrade" / "2 Extra Team Seats"

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
