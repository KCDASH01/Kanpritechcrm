<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->string('legal_name')->nullable();
            $table->string('trade_name')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('signature_path')->nullable();
            $table->text('registered_address')->nullable();
            $table->text('billing_address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2)->default('IN');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10)->nullable();
            $table->json('tax_registrations')->nullable();
            $table->string('default_currency', 3)->default('INR');
            $table->json('supported_currencies')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->text('default_notes')->nullable();
            $table->text('document_footer')->nullable();
            $table->string('default_template', 32)->default('classic');
            $table->unsignedTinyInteger('financial_year_start_month')->default(4);
            $table->json('tax_settings')->nullable();
            $table->json('permissions')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('billing_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_profile_id')->constrained()->restrictOnDelete();
            $table->string('account_name');
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('ifsc', 20)->nullable();
            $table->string('swift', 20)->nullable();
            $table->string('upi_id')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('currency', 3)->default('INR');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['organization_id', 'is_active'], 'bill_bank_org_active_idx');
        });

        Schema::create('billing_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 32);
            $table->string('prefix')->default('');
            $table->string('suffix')->default('');
            $table->unsignedBigInteger('starting_number')->default(1);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(4);
            $table->string('numbering_period', 24)->default('financial_year');
            $table->string('last_period_key', 20)->nullable();
            $table->boolean('reset_on_period_change')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'document_type'], 'bill_seq_org_type_unique');
        });

        Schema::create('billing_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('recurring_business_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('source_document_id')->nullable()->constrained('billing_documents')->restrictOnDelete();
            $table->string('document_type', 32);
            $table->string('status', 24)->default('draft');
            $table->string('document_number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('INR');
            $table->json('issuer_snapshot')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->json('tax_context')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('taxable_total', 18, 2)->default(0);
            $table->decimal('cgst_total', 18, 2)->default(0);
            $table->decimal('sgst_total', 18, 2)->default(0);
            $table->decimal('igst_total', 18, 2)->default(0);
            $table->decimal('rounding_adjustment', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->string('template', 32)->default('classic');
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->text('footer')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'document_type', 'document_number'], 'bill_doc_org_type_no_unique');
            $table->index(['organization_id', 'status', 'issue_date'], 'bill_doc_org_status_date_idx');
        });

        Schema::create('billing_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('description');
            $table->string('hsn_sac', 32)->nullable();
            $table->decimal('quantity', 15, 4);
            $table->string('unit', 32)->nullable();
            $table->decimal('unit_price', 18, 2);
            $table->string('discount_type', 16)->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('taxable_amount', 18, 2)->default(0);
            $table->decimal('gst_rate', 7, 4)->default(0);
            $table->decimal('cgst_amount', 18, 2)->default(0);
            $table->decimal('sgst_amount', 18, 2)->default(0);
            $table->decimal('igst_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['billing_document_id', 'line_number'], 'bill_item_doc_line_unique');
        });

        Schema::create('billing_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('deal_payment_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->date('allocated_on');
            $table->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->index(['deal_payment_id', 'reversed_at'], 'bill_alloc_payment_active_idx');
            $table->index(['billing_document_id', 'reversed_at'], 'bill_alloc_doc_active_idx');
        });

        Schema::create('billing_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('deal_payment_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number');
            $table->json('issuer_snapshot');
            $table->json('customer_snapshot')->nullable();
            $table->json('payment_snapshot');
            $table->string('status', 16)->default('issued');
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'receipt_number'], 'bill_receipt_org_no_unique');
            $table->unique(['organization_id', 'deal_payment_id'], 'bill_receipt_org_payment_unique');
        });

        Schema::create('billing_consolidated_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('represented_total', 18, 2)->default(0);
            $table->json('issuer_snapshot');
            $table->json('customer_snapshot');
            $table->string('status', 16)->default('issued');
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'receipt_number'], 'bill_con_receipt_org_no_unique');
        });

        Schema::create('billing_consolidated_receipt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_consolidated_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('deal_payment_id')->constrained()->restrictOnDelete();
            $table->decimal('amount_snapshot', 18, 2);
            $table->timestamps();
            $table->unique(['billing_consolidated_receipt_id', 'deal_payment_id'], 'bill_con_receipt_payment_unique');
        });

        Schema::create('billing_recurring_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('recurring_business_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('template_document_id')->nullable()->constrained('billing_documents')->restrictOnDelete();
            $table->string('frequency', 24);
            $table->date('start_date');
            $table->date('next_run_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('max_cycles')->nullable();
            $table->unsignedInteger('completed_cycles')->default(0);
            $table->boolean('auto_issue')->default(false);
            $table->boolean('auto_send')->default(false);
            $table->string('status', 16)->default('draft');
            $table->json('delivery_settings')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'next_run_date'], 'bill_recurring_due_idx');
        });

        Schema::create('billing_delivery_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_document_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('billing_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('billing_consolidated_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('channel', 16);
            $table->string('recipient');
            $table->string('status', 20)->default('pending');
            $table->string('provider_reference')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'created_at'], 'bill_delivery_org_date_idx');
        });

        Schema::create('billing_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 64);
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at'], 'bill_audit_org_date_idx');
            $table->index(['auditable_type', 'auditable_id'], 'bill_audit_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_audit_logs');
        Schema::dropIfExists('billing_delivery_history');
        Schema::dropIfExists('billing_recurring_schedules');
        Schema::dropIfExists('billing_consolidated_receipt_payments');
        Schema::dropIfExists('billing_consolidated_receipts');
        Schema::dropIfExists('billing_receipts');
        Schema::dropIfExists('billing_payment_allocations');
        Schema::dropIfExists('billing_document_items');
        Schema::dropIfExists('billing_documents');
        Schema::dropIfExists('billing_document_sequences');
        Schema::dropIfExists('billing_bank_accounts');
        Schema::dropIfExists('billing_profiles');
    }
};
