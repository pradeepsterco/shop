<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            if (Schema::hasColumn('cart_items', 'offer_id') && ! Schema::hasColumn('cart_items', 'listing_id')) {
                $table->renameColumn('offer_id', 'listing_id');
            }
            if (Schema::hasColumn('cart_items', 'seller_id') && ! Schema::hasColumn('cart_items', 'seller_profile_id')) {
                $table->renameColumn('seller_id', 'seller_profile_id');
            }
            if (Schema::hasColumn('cart_items', 'variant_id') && ! Schema::hasColumn('cart_items', 'product_variant_id')) {
                $table->renameColumn('variant_id', 'product_variant_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            if (Schema::hasColumn('cart_items', 'listing_id')) {
                $table->renameColumn('listing_id', 'offer_id');
            }
            if (Schema::hasColumn('cart_items', 'seller_profile_id')) {
                $table->renameColumn('seller_profile_id', 'seller_id');
            }
            if (Schema::hasColumn('cart_items', 'product_variant_id')) {
                $table->renameColumn('product_variant_id', 'variant_id');
            }
        });
    }
};
