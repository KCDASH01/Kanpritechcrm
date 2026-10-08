<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leads', 'types')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->enum('types', ['webapp_development', 'mobile_app_development', 'website_development', 'digital_marketing', 'others'])
                    ->nullable()->after('source');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('leads', 'types')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropColumn('types');
            });
        }
    }
};
