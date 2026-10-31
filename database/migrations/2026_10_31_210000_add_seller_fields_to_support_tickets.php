<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('seller_profile_id')->nullable()->after('user_id')->constrained('seller_profiles')->nullOnDelete();
            // The seller's package within order_id; no foreign key, Helpdesk does not own orders
            $table->ulid('seller_order_id')->nullable()->after('order_number')->index();

            $table->index(['requester_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['requester_type', 'status']);
            $table->dropIndex(['seller_order_id']);
            $table->dropConstrainedForeignId('seller_profile_id');
            $table->dropColumn('seller_order_id');
        });
    }
};
