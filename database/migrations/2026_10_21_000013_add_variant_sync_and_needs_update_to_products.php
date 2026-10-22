<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('needs_update')->default(false)->after('qc_notes');
            $table->json('needs_update_reason')->nullable()->after('needs_update');
            $table->index('needs_update');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->softDeletes()->after('qc_notes');
        });

        // Add check constraints to listings if supported by database engine (MySQL / PostgreSQL)
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement('ALTER TABLE listings ADD CONSTRAINT chk_listing_mrp CHECK (mrp > 0)');
            DB::statement('ALTER TABLE listings ADD CONSTRAINT chk_listing_selling_price CHECK (selling_price > 0)');
            DB::statement('ALTER TABLE listings ADD CONSTRAINT chk_listing_price_lte_mrp CHECK (selling_price <= mrp)');
            DB::statement('ALTER TABLE listings ADD CONSTRAINT chk_listing_stock CHECK (stock >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement('ALTER TABLE listings DROP CONSTRAINT IF EXISTS chk_listing_mrp');
            DB::statement('ALTER TABLE listings DROP CONSTRAINT IF EXISTS chk_listing_selling_price');
            DB::statement('ALTER TABLE listings DROP CONSTRAINT IF EXISTS chk_listing_price_lte_mrp');
            DB::statement('ALTER TABLE listings DROP CONSTRAINT IF EXISTS chk_listing_stock');
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['needs_update']);
            $table->dropColumn(['needs_update', 'needs_update_reason']);
        });
    }
};
