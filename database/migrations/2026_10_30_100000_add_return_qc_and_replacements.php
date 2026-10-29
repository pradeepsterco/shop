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
        Schema::table('return_requests', function (Blueprint $table) {
            // Return parcel delivered to the seller; seller condition report due by report_deadline_at
            $table->timestamp('received_at')->nullable()->after('reverse_shipment_id');
            $table->timestamp('report_deadline_at')->nullable()->after('received_at');
            $table->string('seller_condition', 20)->nullable()->after('report_deadline_at'); // intact, damaged, wrong_item, incomplete
            $table->text('seller_report_notes')->nullable()->after('seller_condition');
            $table->json('seller_report_photos')->nullable()->after('seller_report_notes');
            $table->timestamp('seller_reported_at')->nullable()->after('seller_report_photos');

            // Admin QC, decided once
            $table->string('qc_result', 10)->nullable()->after('seller_reported_at'); // passed, failed
            $table->string('qc_outcome', 30)->nullable()->after('qc_result'); // refund, replacement, ship_back, partial_refund, full_refund, converted_to_refund, lost_refund
            $table->string('qc_fault', 20)->nullable()->after('qc_outcome'); // seller_fault, customer_remorse, courier_fault
            $table->text('qc_notes')->nullable()->after('qc_fault');
            $table->unsignedBigInteger('qc_by_admin_id')->nullable()->after('qc_notes');
            $table->timestamp('qc_at')->nullable()->after('qc_by_admin_id');

            $table->string('refund_id', 26)->nullable()->after('qc_at');
            $table->string('replacement_seller_order_id', 26)->nullable()->index()->after('refund_id');
            $table->string('ship_back_shipment_id', 26)->nullable()->index()->after('replacement_seller_order_id');
        });

        Schema::table('seller_orders', function (Blueprint $table) {
            // A ₹0 replacement package created by a return; returning it again can only be refunded
            $table->string('replacement_of_return_request_id', 26)->nullable()->index()->after('expected_cod_amount_paise');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropIndex(['replacement_of_return_request_id']);
            $table->dropColumn('replacement_of_return_request_id');
        });

        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropIndex(['replacement_seller_order_id']);
            $table->dropIndex(['ship_back_shipment_id']);
            $table->dropColumn([
                'received_at', 'report_deadline_at', 'seller_condition', 'seller_report_notes', 'seller_report_photos', 'seller_reported_at',
                'qc_result', 'qc_outcome', 'qc_fault', 'qc_notes', 'qc_by_admin_id', 'qc_at',
                'refund_id', 'replacement_seller_order_id', 'ship_back_shipment_id',
            ]);
        });
    }
};
