<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE lead_timeline MODIFY COLUMN action ENUM(
            'created',
            'updated',
            'status_changed',
            'assigned',
            'deal_created',
            'deal_won',
            'followup_created',
            'activity_scheduled',
            'note_added',
            'status_remark',
            'converted',
            'deleted',
            'restored',
            'whatsapp'
        ) NOT NULL DEFAULT 'updated'");
    }

    public function down(): void
    {
        DB::table('lead_timeline')
            ->whereIn('action', ['status_remark', 'deal_won', 'whatsapp'])
            ->update(['action' => 'updated']);

        DB::statement("ALTER TABLE lead_timeline MODIFY COLUMN action ENUM(
            'created',
            'updated',
            'status_changed',
            'assigned',
            'deal_created',
            'followup_created',
            'activity_scheduled',
            'note_added',
            'converted',
            'deleted',
            'restored'
        ) NOT NULL DEFAULT 'updated'");
    }
};
