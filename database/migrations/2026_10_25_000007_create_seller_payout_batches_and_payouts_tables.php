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
        Schema::create('seller_payout_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('batch_number')->unique();
            $table->date('cutoff_date')->index();
            $table->string('status', 30)->default('draft')->index();
            $table->unsignedInteger('total_sellers_count')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->timestamps();
        });

        Schema::create('seller_payouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payout_batch_id')->constrained('seller_payout_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('seller_profile_id')->index();
            $table->unsignedBigInteger('gross_settlement_amount');
            $table->unsignedBigInteger('carried_balance_deduction')->default(0);
            $table->unsignedBigInteger('net_amount');
            $table->string('status', 30)->default('draft')->index();
            $table->string('bank_account_holder');
            $table->string('bank_account_number_masked');
            $table->string('bank_ifsc', 20);
            $table->string('bank_fingerprint', 100);
            $table->string('utr', 100)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->unsignedBigInteger('paid_by_admin_id')->nullable();
            $table->timestamps();

            $table->index(['seller_profile_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seller_payouts');
        Schema::dropIfExists('seller_payout_batches');
    }
};
