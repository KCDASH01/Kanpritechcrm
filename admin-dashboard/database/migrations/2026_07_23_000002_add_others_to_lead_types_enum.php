<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leads', 'types')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->enum('types', ['webapp_development', 'mobile_app_development', 'website_development', 'digital_marketing', 'others'])
                ->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('leads', 'types')) {
            return;
        }

        DB::table('leads')->where('types', 'others')->update(['types' => null]);

        Schema::table('leads', function (Blueprint $table) {
            $table->enum('types', ['webapp_development', 'mobile_app_development', 'website_development', 'digital_marketing'])
                ->nullable()->change();
        });
    }
};
