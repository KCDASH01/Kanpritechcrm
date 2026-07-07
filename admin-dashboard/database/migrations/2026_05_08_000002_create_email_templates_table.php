<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');           // e.g. "Initial Outreach"
            $table->string('subject');        // email subject line
            $table->text('body');             // supports {{lead_name}}, {{rep_name}}, {{company}}
            $table->string('stage_trigger')->nullable(); // optional: suggest when lead is in this stage
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
