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
        Schema::table('shipment_events', function (Blueprint $table) {
            $table->string('event_type', 40)->default('courier_update')->after('event_key');
        });

        DB::table('shipment_events')->where('status', 'created')->where('event_key', 'like', '%:created:%')
            ->update(['event_type' => 'shipment_created']);
        DB::table('shipment_events')->where('status', 'cancelled')->where('event_key', 'like', '%:cancelled:%')
            ->update(['event_type' => 'shipment_cancelled']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipment_events', function (Blueprint $table) {
            $table->dropColumn('event_type');
        });
    }
};
