<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            // Original quoted value — locked when deal is created / first counter-offer entered
            $table->decimal('original_value', 15, 2)->nullable()->after('value');

            // Client's counter-offer value
            $table->decimal('counter_offer_value', 15, 2)->nullable()->after('original_value');

            // Free-form notes specific to negotiation (pricing rationale, terms, blockers)
            $table->text('negotiation_notes')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['original_value', 'counter_offer_value', 'negotiation_notes']);
        });
    }
};
