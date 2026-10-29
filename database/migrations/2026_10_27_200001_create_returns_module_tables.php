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
        Schema::create('rto_intake_reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('seller_order_id')->index();
            $table->ulid('shipment_id')->index();
            $table->unsignedBigInteger('seller_profile_id')->index();
            $table->string('condition_status'); // intact, damaged, wrong_item, incomplete
            $table->text('notes')->nullable();
            $table->json('photos')->nullable();
            $table->timestamp('reported_at');
            $table->string('admin_decision')->default('pending'); // pending, restocked, written_off
            $table->timestamp('admin_decided_at')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('courier_claims', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('claim_number')->unique();
            $table->ulid('shipment_id')->index();
            $table->string('seller_order_id')->index();
            $table->string('carrier_name');
            $table->string('claim_type'); // damaged_rto, incomplete_rto, lost_shipment, missing_item
            $table->unsignedBigInteger('claim_amount_paise');
            $table->string('bearer')->default('platform'); // platform, courier, seller
            $table->string('status')->default('submitted'); // submitted, approved, rejected, settled
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('carrier_claim_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courier_claims');
        Schema::dropIfExists('rto_intake_reports');
    }
};
