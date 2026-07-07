<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');              // Display name, e.g. "Initial Follow-up"
            $table->text('message');             // Template body — supports {{name}}, {{company}}
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed 4 default templates
        DB::table('whatsapp_templates')->insert([
            ['name' => 'Follow-up',        'message' => 'Hi {{name}}, just following up on your enquiry. When would be a good time to connect?',  'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Quote Ready',      'message' => "Hi {{name}}, your quote is ready. Let me know if you'd like to discuss it.",              'is_active' => true, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Proposal Review',  'message' => "Hi {{name}}, hope you're well! Have you had a chance to review our proposal?",           'is_active' => true, 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Next Steps',       'message' => "Hi {{name}}, great news — everything is confirmed. Let's move to the next step.",        'is_active' => true, 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
