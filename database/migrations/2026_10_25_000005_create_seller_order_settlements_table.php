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
        Schema::create('seller_order_settlements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('seller_order_id')->unique();
            $table->string('order_id')->index();
            $table->unsignedBigInteger('seller_profile_id')->index();
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('total_deductions');
            $table->bigInteger('net_payout');
            $table->unsignedBigInteger('claimable_credits');
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedSmallInteger('return_window_days')->default(7);
            $table->timestamp('hold_until')->nullable()->index();
            $table->ulid('payout_id')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['seller_profile_id', 'status']);
            $table->index(['status', 'hold_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seller_order_settlements');
    }
};
