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
        Schema::table('verticals', function (Blueprint $table) {
            // Non-returnable verticals still accept damaged/defective/wrong/missing claims (returns.claim_window_days)
            $table->boolean('is_returnable')->default(true)->after('gst_rate');
            // Days after delivery; null = returns.default_return_window_days
            $table->unsignedSmallInteger('return_window_days')->nullable()->after('is_returnable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('verticals', function (Blueprint $table) {
            $table->dropColumn(['is_returnable', 'return_window_days']);
        });
    }
};
