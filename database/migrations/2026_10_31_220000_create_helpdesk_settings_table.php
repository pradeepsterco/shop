<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single row: the grievance officer required by the Consumer Protection (E-Commerce) Rules, 2020
        Schema::create('helpdesk_settings', function (Blueprint $table) {
            $table->id();
            $table->string('grievance_name', 120)->nullable();
            $table->string('grievance_designation', 120)->nullable();
            $table->string('grievance_email', 191)->nullable();
            $table->string('grievance_phone', 30)->nullable();
            $table->text('grievance_address')->nullable();
            $table->string('grievance_hours', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_settings');
    }
};
