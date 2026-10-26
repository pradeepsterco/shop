<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('effective_from')->nullable()->index();
            $table->timestamp('effective_to')->nullable()->index();
            $table->decimal('gst_rate', 5, 2)->default(18.00); // 18.00%
            $table->decimal('tcs_rate', 5, 2)->default(0.50);  // 0.50%
            $table->decimal('tds_standard_rate', 5, 2)->default(0.10); // 0.10%
            $table->decimal('tds_no_pan_rate', 5, 2)->default(5.00);   // 5.00%
            $table->unsignedInteger('default_weight_grams')->default(500);
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_schedules');
    }
};
