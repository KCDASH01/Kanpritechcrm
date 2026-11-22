<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('uploader_name');
            $table->string('filename');
            $table->string('file_path')->nullable();
            $table->string('format', 4);
            $table->unsignedInteger('file_size');
            $table->string('status', 24)->default('uploaded');
            $table->mediumText('sheets');
            $table->mediumText('mapping')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'uploaded_by', 'id'], 'lead_imports_owner_idx');
            $table->index(['status', 'updated_at'], 'lead_imports_state_idx');
        });

        Schema::create('lead_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_row');
            $table->string('status', 16);
            $table->mediumText('payload')->nullable();
            $table->json('issues')->nullable();
            $table->boolean('warning')->default(false);
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['lead_import_id', 'source_row'], 'lead_import_rows_source_unique');
            $table->index(['lead_import_id', 'status', 'id'], 'lead_import_rows_state_idx');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('lead_import_email_key', 255)->virtualAs('lower(trim(email))');
            $table->index(['organization_id', 'lead_import_email_key'], 'leads_org_import_email_idx');
            $table->index(['organization_id', 'website'], 'leads_org_import_website_idx');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_org_import_email_idx');
            $table->dropIndex('leads_org_import_website_idx');
        });
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn('lead_import_email_key'));
        Schema::dropIfExists('lead_import_rows');
        Schema::dropIfExists('lead_imports');
    }
};
