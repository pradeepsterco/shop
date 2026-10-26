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
        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('order_number', 50)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('checkout_intent_id', 50)->unique();
            $table->string('status', 30)->default('placed');
            $table->json('delivery_address');
            $table->string('delivery_state', 100);
            $table->string('payment_method', 30)->default('cash_on_delivery');
            $table->string('payment_status', 30)->default('pending');
            $table->unsignedBigInteger('items_mrp_total');
            $table->unsignedBigInteger('discount_total');
            $table->unsignedBigInteger('delivery_fee')->default(0);
            $table->unsignedBigInteger('grand_total');
            $table->timestamp('placed_at');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('placed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
