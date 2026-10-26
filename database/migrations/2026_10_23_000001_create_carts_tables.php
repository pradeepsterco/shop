<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            // One cart per user; guests are identified by a hashed cookie token.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->char('guest_token', 64)->nullable()->unique();
            $table->char('currency', 3)->default('INR');
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('offer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('seller_id');

            // Display snapshot (refreshed from the offer by CartService::view)
            $table->string('seller_name')->nullable();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('image')->nullable();

            $table->unsignedInteger('qty');

            // Money is stored as integer minor units (paise).
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('mrp');
            $table->unsignedBigInteger('prev_unit_price')->nullable(); // set when price changed, cleared when user dismisses

            $table->string('availability', 20)->default('ok'); // ok | out_of_stock | unavailable
            $table->unsignedInteger('available_qty')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['cart_id', 'offer_id']);   // same offer can never be two rows
            $table->index(['cart_id', 'seller_id']);
            $table->index('offer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
