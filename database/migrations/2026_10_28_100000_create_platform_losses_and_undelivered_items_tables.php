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
        // Money the platform absorbs (e.g. pays the seller for goods the courier lost), kept apart from the courier claim
        Schema::create('platform_losses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('loss_type', 40); // lost_shipment
            $table->string('seller_order_id')->index();
            $table->ulid('shipment_id');
            $table->ulid('courier_claim_id')->nullable()->index();
            $table->unsignedBigInteger('loss_amount_paise'); // seller payout the platform funds without collecting from the customer
            $table->unsignedBigInteger('declared_value_paise'); // value declared to the courier (claim basis)
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'loss_type']);
        });

        // Items the courier did not deliver on a partial delivery, awaiting phase B missing-item handling
        Schema::create('undelivered_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('seller_order_id')->index();
            $table->ulid('shipment_id');
            $table->unsignedBigInteger('order_item_id');
            $table->string('status', 30)->default('pending'); // pending (phase B decides the outcome)
            $table->unsignedBigInteger('recorded_by_admin_id')->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'order_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('undelivered_items');
        Schema::dropIfExists('platform_losses');
    }
};
