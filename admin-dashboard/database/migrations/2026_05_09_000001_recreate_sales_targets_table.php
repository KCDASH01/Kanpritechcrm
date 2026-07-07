<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sales_targets');

        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('target_amount', 15, 2)->default(0);       // sales quota set by admin
            $table->decimal('receivable_amount', 15, 2)->default(0);   // collection target set by admin
            $table->decimal('received_amount', 15, 2)->nullable();     // actual collections — entered manually
            $table->string('notes')->nullable();
            $table->date('period_start');   // first day of the month e.g. 2026-05-01
            $table->timestamps();

            $table->unique(['organization_id', 'user_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
    }
};
