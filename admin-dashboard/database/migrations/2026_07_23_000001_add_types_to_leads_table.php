<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leads', 'types')) {
            DB::statement("ALTER TABLE leads ADD COLUMN types ENUM(
                'webapp_development',
                'mobile_app_development',
                'website_development',
                'digital_marketing',
                'others'
            ) NULL AFTER source");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('leads', 'types')) {
            DB::statement('ALTER TABLE leads DROP COLUMN types');
        }
    }
};
