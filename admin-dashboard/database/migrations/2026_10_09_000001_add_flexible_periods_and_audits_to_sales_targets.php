<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_targets', function (Blueprint $table) {
            $table->string('target_type', 20)->default('monthly')->after('user_id');
            $table->date('period_end')->nullable()->after('period_start');
            $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->index(['organization_id', 'period_start', 'period_end'], 'sales_targets_period_lookup');
        });

        DB::table('sales_targets')->orderBy('id')->chunkById(200, function ($targets): void {
            foreach ($targets as $target) {
                $start = Carbon::parse($target->period_start)->startOfDay();
                DB::table('sales_targets')->where('id', $target->id)->update([
                    'target_type' => 'monthly',
                    'period_start' => $start->toDateString(),
                    'period_end' => $start->copy()->endOfMonth()->toDateString(),
                ]);
            }
        });

        Schema::create('sales_target_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_target_id')->constrained('sales_targets')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'sales_target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_target_audits');

        Schema::table('sales_targets', function (Blueprint $table) {
            $table->dropIndex('sales_targets_period_lookup');
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['target_type', 'period_end', 'created_by', 'updated_by']);
        });
    }
};
