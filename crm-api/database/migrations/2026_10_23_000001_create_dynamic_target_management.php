<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('target_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit_type', 24);
            $table->string('unit_label', 32)->nullable();
            $table->string('metric_source', 64)->default('manual');
            $table->string('direction', 16)->default('higher');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'tdef_org_code_uq');
            $table->index(['organization_id', 'is_active'], 'tdef_org_active_idx');
        });

        Schema::create('target_definition_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_definition_id')->constrained('target_definitions')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['target_definition_id', 'department_id'], 'tdef_dept_uq');
        });

        Schema::create('target_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_definition_id')->constrained('target_definitions')->restrictOnDelete();
            $table->string('scope_type', 24);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('period_type', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_value', 20, 4);
            $table->string('status', 16)->default('active');
            $table->string('definition_code', 64);
            $table->string('definition_name');
            $table->string('unit_type', 24);
            $table->string('unit_label', 32)->nullable();
            $table->string('metric_source', 64);
            $table->string('direction', 16);
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'period_start', 'period_end'], 'tassign_org_period_idx');
            $table->index(['organization_id', 'user_id', 'status'], 'tassign_org_user_status_idx');
            $table->index(['organization_id', 'department_id', 'status'], 'tassign_org_dept_status_idx');
        });

        Schema::create('manual_target_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_assignment_id')->constrained('target_assignments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('subject_key', 32)->default('scope');
            $table->date('entry_date');
            $table->decimal('actual_value', 20, 4);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['target_assignment_id', 'subject_key', 'entry_date'], 'mtentry_assignment_subject_date_uq');
            $table->index(['organization_id', 'entry_date'], 'mtentry_org_date_idx');
        });

        Schema::create('target_management_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 24);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'entity_type', 'entity_id'], 'tm_audit_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_management_audits');
        Schema::dropIfExists('manual_target_entries');
        Schema::dropIfExists('target_assignments');
        Schema::dropIfExists('target_definition_departments');
        Schema::dropIfExists('target_definitions');
    }
};
