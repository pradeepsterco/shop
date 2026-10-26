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
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('seller_order_id')->constrained('seller_orders')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('changed_by_type', 30); // 'customer', 'seller', 'admin', 'system'
            $table->unsignedBigInteger('changed_by_id')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('seller_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
