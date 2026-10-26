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
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['user_id', 'placed_at'], 'orders_user_placed_idx');
            $table->index(['status', 'placed_at'], 'orders_status_placed_idx');
            $table->index('grand_total', 'orders_grand_total_idx');
        });

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->index(['seller_profile_id', 'status', 'placed_at'], 'seller_orders_seller_status_placed_idx');
            $table->index(['seller_profile_id', 'placed_at'], 'seller_orders_seller_placed_idx');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->index('sku', 'order_items_sku_idx');
            $table->index('upid', 'order_items_upid_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_sku_idx');
            $table->dropIndex('order_items_upid_idx');
        });

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropIndex('seller_orders_seller_status_placed_idx');
            $table->dropIndex('seller_orders_seller_placed_idx');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_user_placed_idx');
            $table->dropIndex('orders_status_placed_idx');
            $table->dropIndex('orders_grand_total_idx');
        });
    }
};
