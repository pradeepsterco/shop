<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An early version of the product_offers migration pointed seller_id at a "sellers" table that never existed.
     * SQLite accepted it, then failed every product delete ("no such table: main.sellers") because the delete
     * cascades into product_offers. Such a table can hold no rows (every insert fails the same way), so recreate it.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! Schema::hasTable('product_offers')) {
            return;
        }

        $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'product_offers')->value('sql');
        if (! preg_match('/references\s+"?sellers"?\s*\(/i', $sql)) {
            return;
        }

        Schema::drop('product_offers');

        Schema::create('product_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('seller_profiles')->cascadeOnDelete();
            $table->string('seller_sku', 100)->nullable();
            $table->decimal('mrp', 12, 2);
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['seller_id', 'product_id', 'variant_id'], 'offers_seller_product_variant_unique');
            $table->index(['product_id', 'status']);
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        // Nothing to undo: the old foreign key was broken.
    }
};
