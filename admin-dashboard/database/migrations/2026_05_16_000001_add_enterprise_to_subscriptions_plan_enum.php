<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Expand the plan ENUM to include 'enterprise'
        DB::statement("ALTER TABLE subscriptions MODIFY COLUMN plan ENUM('free', 'business', 'enterprise') NOT NULL DEFAULT 'free'");
    }

    public function down(): void
    {
        // Revert to original ENUM (only safe if no 'enterprise' rows exist)
        DB::statement("ALTER TABLE subscriptions MODIFY COLUMN plan ENUM('free', 'business') NOT NULL DEFAULT 'free'");
    }
};
