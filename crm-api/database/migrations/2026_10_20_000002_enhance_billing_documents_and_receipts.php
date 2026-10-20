<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_profiles', function (Blueprint $table) {
            $table->text('default_terms')->nullable()->after('default_notes');
            $table->string('default_payment_link', 2048)->nullable()->after('default_terms');
        });

        Schema::table('billing_consolidated_receipts', function (Blueprint $table) {
            $table->foreignId('billing_document_id')->nullable()->after('client_id')
                ->constrained('billing_documents', indexName: 'bill_con_receipt_document_fk')->restrictOnDelete();
            $table->json('invoice_snapshot')->nullable()->after('customer_snapshot');
        });

        Schema::table('billing_consolidated_receipt_payments', function (Blueprint $table) {
            $table->json('payment_snapshot')->nullable()->after('amount_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('billing_consolidated_receipt_payments', function (Blueprint $table) {
            $table->dropColumn('payment_snapshot');
        });

        Schema::table('billing_consolidated_receipts', function (Blueprint $table) {
            $table->dropForeign('bill_con_receipt_document_fk');
            $table->dropColumn(['billing_document_id', 'invoice_snapshot']);
        });

        Schema::table('billing_profiles', function (Blueprint $table) {
            $table->dropColumn(['default_terms', 'default_payment_link']);
        });
    }
};
