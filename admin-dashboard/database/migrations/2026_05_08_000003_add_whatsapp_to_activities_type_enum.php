<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ALTER the ENUM column to include 'whatsapp'
        DB::statement("ALTER TABLE activities MODIFY COLUMN type ENUM('call','email','meeting','task','note','deadline','whatsapp') NOT NULL DEFAULT 'task'");
    }

    public function down(): void
    {
        // Remove whatsapp — existing rows with 'whatsapp' will become '' (truncated); acceptable for rollback
        DB::statement("ALTER TABLE activities MODIFY COLUMN type ENUM('call','email','meeting','task','note','deadline') NOT NULL DEFAULT 'task'");
    }
};
