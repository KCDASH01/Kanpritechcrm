<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deals')) {
            return;
        }

        // Existing deals were always displayed as INR; the column default was USD.
        DB::table('deals')->where('currency', 'USD')->update(['currency' => 'INR']);

        DB::statement("ALTER TABLE deals MODIFY currency VARCHAR(3) NOT NULL DEFAULT 'INR'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('deals')) {
            return;
        }

        DB::statement("ALTER TABLE deals MODIFY currency VARCHAR(3) NOT NULL DEFAULT 'USD'");
    }
};
