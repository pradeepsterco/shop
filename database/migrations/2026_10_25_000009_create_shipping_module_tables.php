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
        Schema::create('shipments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('seller_order_id')->index();
            $table->string('order_id')->index();
            $table->unsignedBigInteger('seller_profile_id')->index();
            $table->string('provider', 30)->index(); // 'ekart', 'manual', 'fake'
            $table->string('awb', 100)->nullable()->index();
            $table->string('vendor_wbn', 100)->nullable();
            $table->string('public_tracking_url', 255)->nullable();
            $table->string('status', 30)->default('created')->index();
            $table->unsignedInteger('actual_weight_grams');
            $table->unsignedInteger('volumetric_weight_grams');
            $table->unsignedInteger('chargeable_weight_grams');
            $table->unsignedSmallInteger('length_cm');
            $table->unsignedSmallInteger('width_cm');
            $table->unsignedSmallInteger('height_cm');
            $table->boolean('is_cod')->default(false);
            $table->unsignedBigInteger('cod_amount_paise')->default(0);
            $table->string('label_path', 255)->nullable();
            $table->timestamp('label_generated_at')->nullable();
            $table->date('pickup_date')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('estimated_carrier_charges_paise')->default(0);
            $table->unsignedBigInteger('actual_carrier_charges_paise')->default(0);
            $table->unsignedSmallInteger('carrier_tat_min_days')->nullable();
            $table->unsignedSmallInteger('carrier_tat_max_days')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->json('raw_metadata')->nullable();
            $table->timestamps();

            $table->index(['seller_profile_id', 'status']);
            $table->index(['status', 'last_polled_at']);
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('shipment_id')->index();
            $table->string('event_key', 200)->unique(); // {shipment_id}:{status}:{timestamp}
            $table->string('provider', 30);
            $table->string('status', 30);
            $table->string('provider_status', 100);
            $table->string('provider_ndr_status', 100)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
        });

        Schema::create('seller_shipping_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_profile_id')->unique();
            $table->string('provider', 30)->default('ekart');
            $table->string('warehouse_alias', 50)->unique();
            $table->boolean('is_registered')->default(false);
            $table->string('registration_status', 30)->default('pending'); // pending, registered, failed
            $table->timestamp('last_registered_at')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('pincode')->nullable();
            $table->string('address_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seller_shipping_profiles');
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
    }
};
