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
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft'); // draft, active, paused, expired
            $table->string('discount_type', 20); // percentage, fixed
            $table->unsignedBigInteger('discount_value'); // percentage points (e.g. 10) or paise (e.g. 50000 for ₹500)
            $table->unsignedBigInteger('max_discount')->nullable(); // paise cap for percentage
            $table->unsignedBigInteger('min_order_amount')->default(0); // paise
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->unsignedInteger('usage_limit_total')->nullable();
            $table->unsignedInteger('usage_limit_per_user')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->boolean('first_order_only')->default(false);
            $table->string('funded_by', 20)->default('platform'); // platform, seller
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'expires_at']);
        });

        Schema::create('coupon_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->string('scope_type', 20); // category, brand, seller, product
            $table->unsignedBigInteger('scope_id');
            $table->string('rule_type', 10); // include, exclude
            $table->timestamps();

            $table->unique(['coupon_id', 'scope_type', 'scope_id'], 'uq_coupon_scope');
            $table->index(['scope_type', 'scope_id']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->string('coupon_code', 32);
            $table->string('customer_reference', 64);
            $table->string('order_reference', 64);
            $table->string('idempotency_key', 64)->unique();
            $table->unsignedBigInteger('discount_amount'); // paise
            $table->json('line_allocations'); // [line_id => paise]
            $table->string('funded_by', 20)->default('platform'); // platform, seller
            $table->string('status', 20)->default('redeemed'); // redeemed, partially_released, released
            $table->unsignedBigInteger('released_amount')->default(0); // paise
            $table->dateTime('redeemed_at');
            $table->dateTime('released_at')->nullable();
            $table->timestamps();

            $table->index(['coupon_id', 'status']);
            $table->index(['coupon_id', 'customer_reference', 'status']);
            $table->index('order_reference');
            $table->index('customer_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupon_scopes');
        Schema::dropIfExists('coupons');
    }
};
