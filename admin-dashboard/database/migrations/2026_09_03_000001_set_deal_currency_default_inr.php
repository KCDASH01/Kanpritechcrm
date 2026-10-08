<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        Schema::table('deals', function (Blueprint $table) {
            $table->string('currency', 3)->default('INR')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('deals')) {
            return;
        }

        Schema::table('deals', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->change();
        });
    }
};
