<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Snapshot of the declared/invoice value sent to the courier at shipment creation.
            // Courier claims (lost, damaged) are raised against this amount.
            $table->unsignedBigInteger('declared_value_paise')->nullable()->after('cod_amount_paise');
        });

        // Backfill: every driver declares the seller order grand total to the courier.
        DB::table('shipments')
            ->whereNull('declared_value_paise')
            ->update([
                'declared_value_paise' => DB::raw('(select grand_total from seller_orders where seller_orders.id = shipments.seller_order_id)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('declared_value_paise');
        });
    }
};
