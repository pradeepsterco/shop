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
        Schema::table('shipments', function (Blueprint $table) {
            // forward: seller -> customer; reverse: customer -> seller (return pickup)
            $table->string('direction', 10)->default('forward')->after('seller_profile_id');
            // original: first shipment of the order; reship: undelivered items sent again;
            // customer_return: reverse pickup; replacement: forward shipment of a replacement order
            $table->string('purpose', 20)->default('original')->after('direction');
            $table->ulid('parent_shipment_id')->nullable()->after('purpose')->index();

            $table->index(['seller_order_id', 'direction', 'purpose']);
        });

        // Which items (and units) a shipment carries. No rows = the whole seller order (all existing shipments).
        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('shipment_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedInteger('qty');
            $table->timestamps();

            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
            $table->unique(['shipment_id', 'order_item_id']);
            $table->index('order_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipment_items');

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['seller_order_id', 'direction', 'purpose']);
            $table->dropIndex(['parent_shipment_id']);
            $table->dropColumn(['direction', 'purpose', 'parent_shipment_id']);
        });
    }
};
