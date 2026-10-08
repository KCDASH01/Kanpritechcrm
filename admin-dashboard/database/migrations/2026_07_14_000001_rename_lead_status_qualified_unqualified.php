<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Expand enum so both old and new values are valid
        $this->changeStatus(['new', 'contacted', 'qualified', 'unqualified', 'ringing', 'important', 'converted', 'lost', 'followup', 'meeting', 'not_interested']);

        // 2) Migrate existing rows
        DB::table('leads')->where('status', 'qualified')->update(['status' => 'ringing']);
        DB::table('leads')->where('status', 'unqualified')->update(['status' => 'important']);

        // 3) Drop old enum values
        $this->changeStatus(['new', 'contacted', 'ringing', 'important', 'converted', 'lost', 'followup', 'meeting', 'not_interested']);
    }

    public function down(): void
    {
        $this->changeStatus(['new', 'contacted', 'qualified', 'unqualified', 'ringing', 'important', 'converted', 'lost', 'followup', 'meeting', 'not_interested']);

        DB::table('leads')->where('status', 'ringing')->update(['status' => 'qualified']);
        DB::table('leads')->where('status', 'important')->update(['status' => 'unqualified']);

        $this->changeStatus(['new', 'contacted', 'qualified', 'unqualified', 'converted', 'lost', 'followup', 'meeting', 'not_interested']);
    }

    private function changeStatus(array $values): void
    {
        Schema::table('leads', function (Blueprint $table) use ($values) {
            $table->enum('status', $values)->default('new')->change();
        });
    }
};
