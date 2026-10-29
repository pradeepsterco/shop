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
        // Courier booking happens after the DB work commits: pending -> in_progress -> booked | failed (retryable)
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('booking_status', 20)->default('booked')->after('creation_step');
            $table->text('booking_error')->nullable()->after('booking_status');
            $table->string('return_reason', 120)->nullable()->after('booking_error');
        });

        Schema::table('seller_order_settlements', function (Blueprint $table) {
            // An open customer return blocks release of the package's settlement
            $table->boolean('is_return_hold')->default(false)->after('is_partial_delivery_hold');
        });

        Schema::create('return_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('order_id')->index();
            $table->string('seller_order_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('reason', 30);
            $table->string('preference', 20); // refund, replacement
            $table->string('status', 20)->index(); // requested, info_requested, approved, received, resolved, rejected, closed
            $table->text('customer_notes')->nullable();
            $table->json('photos')->nullable();
            $table->text('info_request_message')->nullable();
            $table->text('customer_response')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('closed_reason', 255)->nullable();
            $table->ulid('reverse_shipment_id')->nullable()->index();
            $table->timestamp('return_deadline_at')->nullable();
            $table->unsignedBigInteger('window_override_by_admin_id')->nullable();
            $table->unsignedBigInteger('decided_by_admin_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('return_request_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('return_request_id');
            $table->unsignedBigInteger('order_item_id')->index();
            $table->unsignedInteger('units');
            $table->unsignedInteger('line_qty');
            $table->timestamps();

            $table->foreign('return_request_id')->references('id')->on('return_requests')->cascadeOnDelete();
        });

        Schema::table('undelivered_items', function (Blueprint $table) {
            // partial_delivery: courier did not deliver; missing_item_claim: customer reported it missing from a delivered package
            $table->string('origin', 30)->default('partial_delivery')->after('status');
            $table->boolean('customer_paid')->default(false)->after('origin');
            $table->ulid('return_request_id')->nullable()->index()->after('customer_paid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('undelivered_items', function (Blueprint $table) {
            $table->dropIndex(['return_request_id']);
            $table->dropColumn(['origin', 'customer_paid', 'return_request_id']);
        });
        Schema::dropIfExists('return_request_items');
        Schema::dropIfExists('return_requests');
        Schema::table('seller_order_settlements', function (Blueprint $table) {
            $table->dropColumn('is_return_hold');
        });
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['booking_status', 'booking_error', 'return_reason']);
        });
    }
};
