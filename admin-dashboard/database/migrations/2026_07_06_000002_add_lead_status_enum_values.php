<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        DB::statement("UPDATE leads SET status = 'new' WHERE status IN ('followup', 'meeting', 'not_interested')");

        DB::statement("ALTER TABLE leads MODIFY COLUMN status ENUM(
            'new',
            'contacted',
            'qualified',
            'unqualified',
            'converted',
            'lost'
        ) NOT NULL DEFAULT 'new'");
    }
};
