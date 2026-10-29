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
        Schema::create('customer_refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('order_id')->index();
            $table->string('seller_order_id')->index();
            $table->unsignedBigInteger('user_id')->index();

            // What the refund is for (e.g. an undelivered item or a return item); one refund per source
            $table->string('source_type', 40);
            $table->string('source_id', 64);

            $table->unsignedBigInteger('amount_paise');
            $table->string('status', 20)->index(); // not_collected, pending, paid, failed

            // Payout destination, encrypted at rest (text columns hold the ciphertext)
            $table->string('payout_method', 10)->nullable(); // upi, bank
            $table->text('upi_id')->nullable();
            $table->text('bank_account_holder')->nullable();
            $table->text('bank_account_number')->nullable();
            $table->text('bank_ifsc')->nullable();

            $table->string('utr', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedBigInteger('processed_by_admin_id')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
        });

        Schema::create('customer_refund_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('customer_refund_id');
            $table->unsignedBigInteger('order_item_id')->index();
            $table->unsignedInteger('units');
            $table->unsignedBigInteger('gross_paise');        // unit price x units
            $table->unsignedBigInteger('coupon_share_paise'); // coupon discount attributable to these units
            $table->unsignedBigInteger('amount_paise');       // gross - coupon share
            $table->timestamps();

            $table->foreign('customer_refund_id')->references('id')->on('customer_refunds')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_refund_items');
        Schema::dropIfExists('customer_refunds');
    }
};
