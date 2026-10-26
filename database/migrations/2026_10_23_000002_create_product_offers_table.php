<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PRODUCTS = 'products';

    private const VARIANTS = 'product_variants';

    private const SELLERS = 'seller_profiles';

    public function up(): void
    {
        if (Schema::hasTable('product_offers')) {
            return;
        }

        Schema::create('product_offers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained(self::PRODUCTS)->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained(self::VARIANTS)->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained(self::SELLERS)->cascadeOnDelete();

            $table->string('seller_sku', 100)->nullable();

            // Rupees with 2 decimals (CartService converts to paise).
            $table->decimal('mrp', 12, 2);
            $table->decimal('price', 12, 2);

            $table->unsignedInteger('stock')->default(0);
            $table->boolean('status')->default(true);   // seller on/off switch; cart treats false as unavailable

            $table->timestamps();
            $table->softDeletes();                       // a deleted offer shows as "unavailable" in carts, not a crash

            $table->unique(['seller_id', 'product_id', 'variant_id'], 'offers_seller_product_variant_unique');
            $table->index(['product_id', 'status']);
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_offers');
    }
};
