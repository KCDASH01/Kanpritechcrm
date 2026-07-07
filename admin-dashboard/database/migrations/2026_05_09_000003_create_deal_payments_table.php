<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->string('payment_mode')->default('bank_transfer'); // cash|cheque|bank_transfer|upi|card|other
            $table->string('txn_or_utr_number')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['deal_id', 'payment_date']);
            $table->index(['organization_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_payments');
    }
};
