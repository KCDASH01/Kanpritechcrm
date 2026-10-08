<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->enum('type', ['call', 'email', 'meeting', 'task', 'note', 'deadline', 'whatsapp'])
                ->default('task')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->enum('type', ['call', 'email', 'meeting', 'task', 'note', 'deadline'])
                ->default('task')
                ->change();
        });
    }
};
