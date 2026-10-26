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
        // 1. Update seller_shipping_profiles to support multiple providers per seller
        Schema::table('seller_shipping_profiles', function (Blueprint $table) {
            $table->dropUnique(['seller_profile_id']);
            $table->dropUnique(['warehouse_alias']);

            $table->unique(['seller_profile_id', 'provider'], 'seller_shipping_profiles_seller_provider_unique');
            $table->unique(['warehouse_alias', 'provider'], 'seller_shipping_profiles_alias_provider_unique');

            $table->boolean('phone_verified')->default(false)->after('is_registered');
        });

        // 2. Add Shiprocket and resumable fulfillment tracking fields to shipments
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('courier_name', 100)->nullable()->after('provider');
            $table->string('provider_order_id', 100)->nullable()->after('vendor_wbn');
            $table->string('provider_shipment_id', 100)->nullable()->after('provider_order_id');
            $table->unsignedSmallInteger('fulfillment_attempt')->default(1)->after('provider_shipment_id');
            $table->string('creation_step', 30)->default('pending')->after('fulfillment_attempt');
            $table->string('manifest_path', 255)->nullable()->after('label_path');

            $table->index(['provider', 'provider_order_id'], 'shipments_provider_order_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('shipments_provider_order_index');
            $table->dropColumn([
                'courier_name',
                'provider_order_id',
                'provider_shipment_id',
                'fulfillment_attempt',
                'creation_step',
                'manifest_path',
            ]);
        });

        Schema::table('seller_shipping_profiles', function (Blueprint $table) {
            $table->dropColumn('phone_verified');
            $table->dropUnique('seller_shipping_profiles_seller_provider_unique');
            $table->dropUnique('seller_shipping_profiles_alias_provider_unique');

            $table->unique('seller_profile_id');
            $table->unique('warehouse_alias');
        });
    }
};
