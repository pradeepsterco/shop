<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Commission slabs
        Schema::create('fee_commission_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_schedule_id')->constrained('fee_schedules')->cascadeOnDelete();
            $table->unsignedBigInteger('vertical_id')->nullable()->index(); // null = fallback/default
            $table->unsignedBigInteger('min_price'); // in paise
            $table->unsignedBigInteger('max_price')->nullable(); // in paise, null = infinite
            $table->unsignedInteger('rate_bps'); // Basis points, e.g. 1000 = 10.00%
            $table->timestamps();

            $table->index(['fee_schedule_id', 'vertical_id', 'min_price'], 'fee_comm_sched_vert_min_idx');
        });

        // 2. Fixed closing fee slabs
        Schema::create('fee_closing_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_schedule_id')->constrained('fee_schedules')->cascadeOnDelete();
            $table->unsignedBigInteger('min_price'); // in paise
            $table->unsignedBigInteger('max_price')->nullable(); // in paise, null = infinite
            $table->unsignedBigInteger('fee_amount'); // in paise, e.g. 2000 = ₹20.00
            $table->timestamps();

            $table->index(['fee_schedule_id', 'min_price'], 'fee_close_sched_min_idx');
        });

        // 3. Shipping fee slabs
        Schema::create('fee_shipping_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_schedule_id')->constrained('fee_schedules')->cascadeOnDelete();
            $table->unsignedInteger('min_weight_grams');
            $table->unsignedInteger('max_weight_grams')->nullable(); // null = infinite
            $table->string('zone', 20); // local, regional, national
            $table->unsignedBigInteger('fee_amount'); // in paise
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['fee_schedule_id', 'zone', 'min_weight_grams'], 'fee_ship_sched_zone_weight_idx');
        });

        // 4. Payment collection rules
        Schema::create('fee_collection_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_schedule_id')->constrained('fee_schedules')->cascadeOnDelete();
            $table->string('payment_method', 30);
            $table->unsignedInteger('rate_bps')->default(0); // basis points, e.g. 200 = 2%
            $table->unsignedBigInteger('fee_amount')->default(0); // in paise
            $table->timestamps();

            $table->index(['fee_schedule_id', 'payment_method'], 'fee_collect_sched_method_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_collection_rules');
        Schema::dropIfExists('fee_shipping_slabs');
        Schema::dropIfExists('fee_closing_slabs');
        Schema::dropIfExists('fee_commission_slabs');
    }
};
