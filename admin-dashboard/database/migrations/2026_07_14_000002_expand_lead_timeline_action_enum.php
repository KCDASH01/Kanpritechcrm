<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_timeline', function (Blueprint $table) {
            $table->enum('action', ['created', 'updated', 'status_changed', 'assigned', 'deal_created', 'deal_won', 'followup_created', 'activity_scheduled', 'note_added', 'status_remark', 'converted', 'deleted', 'restored', 'whatsapp'])
                ->default('updated')->change();
        });
    }

    public function down(): void
    {
        DB::table('lead_timeline')
            ->whereIn('action', ['status_remark', 'deal_won', 'whatsapp'])
            ->update(['action' => 'updated']);

        Schema::table('lead_timeline', function (Blueprint $table) {
            $table->enum('action', ['created', 'updated', 'status_changed', 'assigned', 'deal_created', 'followup_created', 'activity_scheduled', 'note_added', 'converted', 'deleted', 'restored'])
                ->default('updated')->change();
        });
    }
};
