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
        Schema::create('branding_settings', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace_name')->default('Khilauna');
            $table->string('short_name')->nullable();
            $table->string('seller_area_name')->nullable();
            $table->string('admin_area_name')->nullable();
            $table->string('support_email')->nullable();

            // Logos (Light & Dark per area + Default + Favicon)
            $table->string('default_logo_light')->nullable();
            $table->string('default_logo_dark')->nullable();
            $table->string('storefront_logo_light')->nullable();
            $table->string('storefront_logo_dark')->nullable();
            $table->string('customer_logo_light')->nullable();
            $table->string('customer_logo_dark')->nullable();
            $table->string('seller_logo_light')->nullable();
            $table->string('seller_logo_dark')->nullable();
            $table->string('admin_logo_light')->nullable();
            $table->string('admin_logo_dark')->nullable();
            $table->string('email_logo_light')->nullable();
            $table->string('email_logo_dark')->nullable();
            $table->string('favicon')->nullable();

            $table->timestamps();
        });

        // Seed initial branding from APP_NAME on fresh install
        $appName = config('app.name');
        if (blank($appName) || $appName === 'Laravel') {
            $appName = 'Khilauna';
        }

        DB::table('branding_settings')->insert([
            'marketplace_name' => $appName,
            'short_name' => $appName,
            'seller_area_name' => "{$appName} Seller Center",
            'admin_area_name' => "{$appName} Admin Panel",
            'support_email' => 'support@'.strtolower(str_replace([' ', '.'], '', $appName)).'.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branding_settings');
    }
};
