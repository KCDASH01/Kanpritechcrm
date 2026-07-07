<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE leads MODIFY COLUMN source ENUM(
            'manual',
            'sso_import',
            'web_form',
            'csv',
            'api',
            'other',
            'meta_ad',
            'email_campaign',
            'google_ads'
        ) NOT NULL DEFAULT 'manual'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE leads MODIFY COLUMN source ENUM(
            'manual',
            'sso_import',
            'web_form',
            'csv',
            'api',
            'other'
        ) NOT NULL DEFAULT 'manual'");
    }
};
