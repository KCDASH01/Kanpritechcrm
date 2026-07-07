<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // Polymorphic: can belong to a Lead or a Deal
            $table->nullableMorphs('subject');  // subject_type, subject_id

            $table->enum('type', ['call', 'email', 'meeting', 'task', 'note', 'deadline'])->default('task');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('is_done')->default(false);
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_done']);
            $table->index(['organization_id', 'assigned_to', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
