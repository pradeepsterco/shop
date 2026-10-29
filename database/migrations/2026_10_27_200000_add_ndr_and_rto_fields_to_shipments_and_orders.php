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
            $table->unsignedTinyInteger('ndr_attempt_count')->default(0)->after('status');
            $table->string('latest_ndr_reason')->nullable()->after('ndr_attempt_count');
            $table->string('ndr_updated_phone')->nullable()->after('latest_ndr_reason');
            $table->text('ndr_updated_address')->nullable()->after('ndr_updated_phone');
            $table->string('ndr_action')->nullable()->after('ndr_updated_address');
            $table->timestamp('ndr_actioned_at')->nullable()->after('ndr_action');
            $table->json('partial_delivery_data')->nullable()->after('ndr_actioned_at');
        });

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->timestamp('rto_delivered_at')->nullable()->after('delivered_at');
        });

        Schema::table('seller_order_settlements', function (Blueprint $table) {
            $table->boolean('is_partial_delivery_hold')->default(false)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seller_order_settlements', function (Blueprint $table) {
            $table->dropColumn('is_partial_delivery_hold');
        });

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropColumn('rto_delivered_at');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'ndr_attempt_count',
                'latest_ndr_reason',
                'ndr_updated_phone',
                'ndr_updated_address',
                'ndr_action',
                'ndr_actioned_at',
                'partial_delivery_data',
            ]);
        });
    }
};
