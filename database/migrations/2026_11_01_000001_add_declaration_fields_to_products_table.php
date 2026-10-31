<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Package declarations shown on the product page (Legal Metrology (Packaged Commodities) Rules, 2011, rule 6(10))
            $table->string('generic_name', 120)->nullable()->after('specs');
            $table->decimal('net_quantity', 10, 3)->nullable()->after('generic_name');
            $table->string('net_quantity_unit', 10)->nullable()->after('net_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['generic_name', 'net_quantity', 'net_quantity_unit']);
        });
    }
};
