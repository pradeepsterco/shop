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
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('buy_box_listing_id')
                ->nullable()
                ->after('qc_notes')
                ->constrained('listings')
                ->nullOnDelete();

            $table->unsignedInteger('min_price')
                ->nullable()
                ->after('buy_box_listing_id')
                ->index();

            $table->unsignedInteger('buy_box_mrp')
                ->nullable()
                ->after('min_price');

            $table->unsignedTinyInteger('discount_percent')
                ->default(0)
                ->after('buy_box_mrp')
                ->index();

            $table->boolean('in_stock')
                ->default(false)
                ->after('discount_percent')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['buy_box_listing_id']);
            $table->dropColumn([
                'buy_box_listing_id',
                'min_price',
                'buy_box_mrp',
                'discount_percent',
                'in_stock',
            ]);
        });
    }
};
