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
            $table->string('coupon_code', 32)->nullable()->after('discount_total');
            $table->unsignedBigInteger('coupon_discount_total')->default(0)->after('coupon_code');
        });

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('coupon_discount_total')->default(0)->after('discount_total');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('coupon_discount_amount')->default(0)->after('line_total');
            $table->string('coupon_funded_by', 20)->nullable()->after('coupon_discount_amount');
        });

        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->string('coupon_code', 32)->nullable()->after('lines');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->dropColumn('coupon_code');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['coupon_discount_amount', 'coupon_funded_by']);
        });

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropColumn('coupon_discount_total');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['coupon_code', 'coupon_discount_total']);
        });
    }
};
