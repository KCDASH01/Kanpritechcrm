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

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `leads` MODIFY COLUMN `types` VARCHAR(255) NULL');

            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->string('types', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'This migration is intentionally irreversible because converting leads.types back to ENUM could truncate catalogue requirement values.'
        );
    }
};
