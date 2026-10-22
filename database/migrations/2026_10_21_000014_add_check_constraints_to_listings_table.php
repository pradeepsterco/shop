<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // MySQL 8.0.16+ and PostgreSQL support ALTER TABLE ADD CONSTRAINT CHECK
        // SQLite does not support adding constraints to existing tables via ALTER TABLE
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            DB::statement('ALTER TABLE listings ADD CONSTRAINT check_listings_mrp CHECK (mrp > 0)');
            DB::statement('ALTER TABLE listings ADD CONSTRAINT check_listings_selling_price CHECK (selling_price > 0)');
            DB::statement('ALTER TABLE listings ADD CONSTRAINT check_listings_price_le_mrp CHECK (selling_price <= mrp)');
            DB::statement('ALTER TABLE listings ADD CONSTRAINT check_listings_stock CHECK (stock >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE listings DROP CHECK check_listings_mrp');
            DB::statement('ALTER TABLE listings DROP CHECK check_listings_selling_price');
            DB::statement('ALTER TABLE listings DROP CHECK check_listings_price_le_mrp');
            DB::statement('ALTER TABLE listings DROP CHECK check_listings_stock');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE listings DROP CONSTRAINT check_listings_mrp');
            DB::statement('ALTER TABLE listings DROP CONSTRAINT check_listings_selling_price');
            DB::statement('ALTER TABLE listings DROP CONSTRAINT check_listings_price_le_mrp');
            DB::statement('ALTER TABLE listings DROP CONSTRAINT check_listings_stock');
        }
    }
};
