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
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->string('courier_name', 100)->nullable()->after('shipped_at');
            $table->string('tracking_number', 100)->nullable()->after('courier_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropColumn(['courier_name', 'tracking_number']);
        });
    }
};
