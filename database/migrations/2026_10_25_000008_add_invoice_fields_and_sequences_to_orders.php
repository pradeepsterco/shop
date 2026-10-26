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
        Schema::create('seller_invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_profile_id')->index();
            $table->string('financial_year', 6)->index(); // e.g. "2627"
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(['seller_profile_id', 'financial_year'], 'seller_fy_sequence_unique');
        });

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->string('invoice_number', 16)->nullable()->after('status')->index();
            $table->date('invoice_date')->nullable()->after('invoice_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropColumn(['invoice_number', 'invoice_date']);
        });

        Schema::dropIfExists('seller_invoice_sequences');
    }
};
