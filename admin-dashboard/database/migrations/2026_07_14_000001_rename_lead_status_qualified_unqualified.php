<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Expand enum so both old and new values are valid
        DB::statement("ALTER TABLE leads MODIFY COLUMN status ENUM(
            'new',
            'contacted',
            'qualified',
            'unqualified',
            'ringing',
            'important',
            'converted',
            'lost',
            'followup',
            'meeting',
            'not_interested'
        ) NOT NULL DEFAULT 'new'");

        // 2) Migrate existing rows
        DB::table('leads')->where('status', 'qualified')->update(['status' => 'ringing']);
        DB::table('leads')->where('status', 'unqualified')->update(['status' => 'important']);

        // 3) Drop old enum values
        DB::statement("ALTER TABLE leads MODIFY COLUMN status ENUM(
            'new',
            'contacted',
            'ringing',
            'important',
            'converted',
            'lost',
            'followup',
            'meeting',
            'not_interested'
        ) NOT NULL DEFAULT 'new'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE leads MODIFY COLUMN status ENUM(
            'new',
            'contacted',
            'qualified',
            'unqualified',
            'ringing',
            'important',
            'converted',
            'lost',
            'followup',
            'meeting',
            'not_interested'
        ) NOT NULL DEFAULT 'new'");

        DB::table('leads')->where('status', 'ringing')->update(['status' => 'qualified']);
        DB::table('leads')->where('status', 'important')->update(['status' => 'unqualified']);

        DB::statement("ALTER TABLE leads MODIFY COLUMN status ENUM(
            'new',
            'contacted',
            'qualified',
            'unqualified',
            'converted',
            'lost',
            'followup',
            'meeting',
            'not_interested'
        ) NOT NULL DEFAULT 'new'");
    }
};
