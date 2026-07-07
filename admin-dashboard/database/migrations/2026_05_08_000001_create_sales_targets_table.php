<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('metric'); // 'revenue' | 'deals_won' | 'leads_converted'
            $table->decimal('target_value', 15, 2)->default(0);
            $table->string('period')->default('monthly');
            $table->date('period_start'); // first day of the target month e.g. 2026-05-01
            $table->timestamps();

            $table->unique(['organization_id', 'user_id', 'metric', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
    }
};
