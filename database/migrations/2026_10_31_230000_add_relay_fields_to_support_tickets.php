<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            // Pairs a customer ticket with the seller ticket staff opened for it (set on both)
            $table->foreignUlid('linked_ticket_id')->nullable()->after('seller_order_id')->constrained('support_tickets')->nullOnDelete();
            // Seller tickets: when the seller's reply is due, and when the one reminder went out
            $table->timestamp('reply_due_at')->nullable()->after('last_message_at');
            $table->timestamp('reminded_at')->nullable()->after('reply_due_at');
            // Inbox marker such as "seller_replied"; cleared when staff act
            $table->string('attention', 30)->nullable()->after('priority');

            $table->index(['status', 'reply_due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['status', 'reply_due_at']);
            $table->dropConstrainedForeignId('linked_ticket_id');
            $table->dropColumn(['reply_due_at', 'reminded_at', 'attention']);
        });
    }
};
