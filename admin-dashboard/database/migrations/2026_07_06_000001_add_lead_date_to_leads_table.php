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
            $table->date('lead_date')->nullable()->after('created_by');
        });

        DB::table('leads')
            ->whereNull('lead_date')
            ->update(['lead_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('lead_date');
        });
    }
};
