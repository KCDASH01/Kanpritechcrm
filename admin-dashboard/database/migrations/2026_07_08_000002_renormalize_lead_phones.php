<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('leads')
            ->select(['id', 'phone', 'phone_normalized'])
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($leads): void {
                foreach ($leads as $lead) {
                    $normalized = $this->normalizePhone($lead->phone);

                    if ($lead->phone_normalized !== $normalized) {
                        DB::table('leads')
                            ->where('id', $lead->id)
                            ->update(['phone_normalized' => $normalized]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Non-reversible data normalization
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
