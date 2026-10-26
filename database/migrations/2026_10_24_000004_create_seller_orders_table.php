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
        Schema::create('seller_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('seller_profile_id')->nullable()->constrained('seller_profiles')->nullOnDelete();
            $table->string('seller_store_name');
            $table->string('seller_gstin', 30)->nullable();
            $table->string('seller_pickup_state', 100)->nullable();
            $table->string('status', 30)->default('placed');
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('grand_total');
            $table->timestamp('placed_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('cancelled_by_type', 30)->nullable();
            $table->unsignedBigInteger('cancelled_by_id')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['seller_profile_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seller_orders');
    }
};
