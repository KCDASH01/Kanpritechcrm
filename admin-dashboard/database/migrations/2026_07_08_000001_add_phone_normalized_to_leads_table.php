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
            $table->string('phone_normalized', 32)->nullable()->after('phone');
        });

        DB::table('leads')
            ->select(['id', 'phone'])
            ->orderBy('id')
            ->chunkById(200, function ($leads): void {
                foreach ($leads as $lead) {
                    $normalized = $this->normalizePhone($lead->phone);

                    DB::table('leads')
                        ->where('id', $lead->id)
                        ->update(['phone_normalized' => $normalized]);
                }
            });

        Schema::table('leads', function (Blueprint $table) {
            $table->index(['organization_id', 'phone_normalized'], 'leads_org_phone_normalized_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_org_phone_normalized_index');
            $table->dropColumn('phone_normalized');
        });
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
            $digits = ltrim($digits, '0');
        }

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        return '91' . $digits;
    }
};
