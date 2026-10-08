<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->enum('source', ['manual', 'sso_import', 'web_form', 'csv', 'api', 'other', 'meta_ad', 'email_campaign', 'google_ads'])
                ->default('manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->enum('source', ['manual', 'sso_import', 'web_form', 'csv', 'api', 'other'])
                ->default('manual')->change();
        });
    }
};
