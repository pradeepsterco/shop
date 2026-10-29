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
        // A seller order keeps one "order" settlement; each reship of undelivered items gets its own
        // "reship" settlement (own credits, own hold), keyed by the reship shipment.
        Schema::table('seller_order_settlements', function (Blueprint $table) {
            $table->string('kind', 20)->default('order')->after('seller_order_id');
            $table->string('reference_id', 64)->default('')->after('kind');
        });

        Schema::table('seller_order_settlements', function (Blueprint $table) {
            $table->dropUnique(['seller_order_id']);
            $table->index('seller_order_id');
            $table->unique(['seller_order_id', 'kind', 'reference_id']);
        });

        Schema::table('undelivered_items', function (Blueprint $table) {
            $table->unsignedInteger('units')->default(1)->after('order_item_id');
            $table->unsignedInteger('line_qty')->default(1)->after('units'); // quantity of the whole order line, for per-unit amounts
            // pending -> refunded | reshipping -> reshipped | lost; a returned or cancelled reship goes back to pending
            $table->string('resolution', 20)->nullable()->after('status'); // refund, reship
            $table->string('refund_id', 26)->nullable()->after('resolution');
            $table->string('reship_shipment_id', 26)->nullable()->index()->after('refund_id');
            $table->unsignedSmallInteger('reship_attempts')->default(0)->after('reship_shipment_id');
            $table->timestamp('resolved_at')->nullable()->after('reship_attempts');
            $table->unsignedBigInteger('resolved_by_admin_id')->nullable()->after('resolved_at');
        });

        // Phase A recorded whole lines as undelivered: units = the line quantity
        $lineQty = DB::raw('(select qty from order_items where order_items.id = undelivered_items.order_item_id)');
        DB::table('undelivered_items')->update(['units' => $lineQty, 'line_qty' => $lineQty]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('undelivered_items', function (Blueprint $table) {
            $table->dropIndex(['reship_shipment_id']);
            $table->dropColumn(['units', 'line_qty', 'resolution', 'refund_id', 'reship_shipment_id', 'reship_attempts', 'resolved_at', 'resolved_by_admin_id']);
        });

        Schema::table('seller_order_settlements', function (Blueprint $table) {
            $table->dropUnique(['seller_order_id', 'kind', 'reference_id']);
            $table->dropIndex(['seller_order_id']);
            $table->unique('seller_order_id');
        });

        Schema::table('seller_order_settlements', function (Blueprint $table) {
            $table->dropColumn(['kind', 'reference_id']);
        });
    }
};
