<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            // Shown to customers on the seller details page (Consumer Protection (E-Commerce) Rules, 2020)
            $table->string('legal_name', 191)->nullable()->after('store_slug');
            $table->timestamp('declaration_accepted_at')->nullable()->after('bank_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'declaration_accepted_at']);
        });
    }
};
