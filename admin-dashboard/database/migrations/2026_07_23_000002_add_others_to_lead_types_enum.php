<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leads', 'types')) {
            return;
        }

        DB::statement("ALTER TABLE leads MODIFY COLUMN types ENUM(
            'webapp_development',
            'mobile_app_development',
            'website_development',
            'digital_marketing',
            'others'
        ) NULL");
    }

    public function down(): void
    {
        if (! Schema::hasColumn('leads', 'types')) {
            return;
        }

        DB::table('leads')->where('types', 'others')->update(['types' => null]);

        DB::statement("ALTER TABLE leads MODIFY COLUMN types ENUM(
            'webapp_development',
            'mobile_app_development',
            'website_development',
            'digital_marketing'
        ) NULL");
    }
};
