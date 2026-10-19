<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_catalogue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->string('code', 64);
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('unit', 32)->nullable();
            $table->string('hsn_sac', 32)->nullable();
            $table->decimal('default_tax_rate', 7, 4)->default(0);
            $table->json('prices')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'code'], 'bill_catalogue_org_code_unique');
            $table->index(['organization_id', 'type', 'is_active'], 'bill_catalogue_org_type_active_idx');
        });

        Schema::table('billing_document_items', function (Blueprint $table) {
            $table->foreignId('catalogue_item_id')->nullable()->after('billing_document_id')
                ->constrained('billing_catalogue_items')->nullOnDelete();
            $table->json('catalogue_snapshot')->nullable()->after('metadata');
        });

        Schema::table('billing_documents', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->string('payment_status', 24)->default('unpaid')->after('status');
            $table->string('delivery_status', 24)->default('not_sent')->after('payment_status');
            $table->timestamp('submitted_at')->nullable()->after('footer');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('approved_by');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable()->after('voided_by');
            $table->foreignId('converted_to_document_id')->nullable()->after('source_document_id')
                ->constrained('billing_documents')->restrictOnDelete();
            $table->string('idempotency_key', 100)->nullable()->after('version');
            $table->unique(['organization_id', 'idempotency_key'], 'bill_doc_org_idempotency_unique');
            $table->index(['organization_id', 'document_type', 'payment_status'], 'bill_doc_org_type_payment_idx');
        });

        Schema::table('billing_profiles', function (Blueprint $table) {
            $table->boolean('approval_required')->default(false)->after('payment_terms_days');
        });
    }

    public function down(): void
    {
        Schema::table('billing_profiles', fn (Blueprint $table) => $table->dropColumn('approval_required'));
        Schema::table('billing_documents', function (Blueprint $table) {
            $table->dropUnique('bill_doc_org_idempotency_unique');
            $table->dropIndex('bill_doc_org_type_payment_idx');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropConstrainedForeignId('converted_to_document_id');
            $table->dropColumn(['payment_status', 'delivery_status', 'submitted_at', 'approved_at', 'voided_at', 'void_reason', 'idempotency_key']);
        });
        Schema::table('billing_document_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalogue_item_id');
            $table->dropColumn('catalogue_snapshot');
        });
        Schema::dropIfExists('billing_catalogue_items');
    }
};
