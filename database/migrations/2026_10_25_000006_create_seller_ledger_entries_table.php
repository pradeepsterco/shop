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
        Schema::create('seller_ledger_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('seller_profile_id')->index();
            $table->string('seller_order_id')->nullable()->index();
            $table->unsignedBigInteger('order_item_id')->nullable()->index();
            $table->ulid('payout_id')->nullable()->index();
            $table->string('type', 30)->index();
            $table->bigInteger('amount'); // Signed paise (Credit +, Debit -)
            $table->string('description');
            $table->string('source_key')->unique();
            $table->timestamp('created_at')->index();

            $table->index(['seller_profile_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seller_ledger_entries');
    }
};
