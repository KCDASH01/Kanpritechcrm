<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_timeline', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')
                  ->constrained('leads')
                  ->onDelete('cascade');

            $table->foreignId('organization_id')
                  ->constrained('organizations')
                  ->onDelete('cascade');

            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // What happened
            $table->enum('action', [
                'created',
                'updated',
                'status_changed',
                'assigned',
                'deal_created',
                'followup_created',
                'activity_scheduled',
                'note_added',
                'converted',
                'deleted',
                'restored',
            ])->default('updated');

            // Human-readable summary shown in the timeline
            $table->string('description', 500);

            // Structured diff / extra metadata (old→new values, IDs, etc.)
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['lead_id',        'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_timeline');
    }
};
