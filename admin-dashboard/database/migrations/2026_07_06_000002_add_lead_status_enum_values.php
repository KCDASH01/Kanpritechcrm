<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->enum('status', ['new', 'contacted', 'qualified', 'unqualified', 'converted', 'lost', 'followup', 'meeting', 'not_interested'])
                ->default('new')->change();
        });
    }

    public function down(): void
    {
        DB::statement("UPDATE leads SET status = 'new' WHERE status IN ('followup', 'meeting', 'not_interested')");

        Schema::table('leads', function (Blueprint $table) {
            $table->enum('status', ['new', 'contacted', 'qualified', 'unqualified', 'converted', 'lost'])
                ->default('new')->change();
        });
    }
};
