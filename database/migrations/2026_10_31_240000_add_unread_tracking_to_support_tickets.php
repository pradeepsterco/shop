<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            // Support posted something the customer or seller has not opened yet
            $table->boolean('requester_unread')->default(false)->after('attention');
            // Last time the requester side wrote (for a case, also the seller on the linked ticket); staff unread is measured against it
            $table->timestamp('requester_activity_at')->nullable()->after('last_message_at');

            $table->index(['user_id', 'requester_type', 'requester_unread']);
        });

        // When each staff member last opened a ticket: unread is per person
        Schema::create('support_ticket_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['support_ticket_id', 'admin_id']);
            $table->index(['admin_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_reads');

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'requester_type', 'requester_unread']);
            $table->dropColumn(['requester_unread', 'requester_activity_at']);
        });
    }
};
