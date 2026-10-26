<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('checkout_intents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20); // 'cart' or 'buy_now'
            $table->json('lines');
            $table->string('status', 20)->default('pending'); // 'pending', 'completed', 'cancelled'
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_at']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkout_intents');
    }
};
