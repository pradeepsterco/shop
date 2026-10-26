<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_fee_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_item_id')->unique();
            $table->string('seller_order_id', 36)->index();
            $table->string('order_id', 36)->index();
            $table->unsignedBigInteger('fee_schedule_id');
            $table->unsignedInteger('schedule_version');
            $table->unsignedBigInteger('selling_price'); // in paise
            $table->unsignedInteger('qty');
            $table->decimal('product_gst_rate', 5, 2);
            $table->unsignedBigInteger('commission_amount'); // paise
            $table->unsignedBigInteger('closing_fee_amount'); // paise
            $table->unsignedBigInteger('shipping_fee_amount'); // paise
            $table->unsignedBigInteger('collection_fee_amount'); // paise
            $table->unsignedBigInteger('gst_on_fees_amount'); // paise
            $table->unsignedBigInteger('tcs_amount'); // paise
            $table->unsignedBigInteger('tds_amount'); // paise
            $table->unsignedBigInteger('total_deductions'); // paise
            $table->unsignedBigInteger('net_payout'); // paise
            $table->unsignedBigInteger('claimable_credits'); // paise
            $table->json('snapshot_data');
            $table->timestamps();

            $table->foreign('fee_schedule_id')->references('id')->on('fee_schedules')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_fee_snapshots');
    }
};
