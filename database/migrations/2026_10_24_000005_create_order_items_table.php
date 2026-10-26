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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('listings')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('title');
            $table->string('variant_title')->nullable();
            $table->string('sku', 100);
            $table->string('upid', 100);
            $table->string('image_path')->nullable();
            $table->string('seller_name');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('mrp');
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('line_total');
            $table->string('hsn_code', 20)->nullable();
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('gst_amount')->default(0);
            $table->timestamps();

            $table->index('seller_order_id');
            $table->index('listing_id');
            $table->index('product_variant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
