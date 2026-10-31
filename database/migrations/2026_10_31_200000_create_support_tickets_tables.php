<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('ticket_number', 20)->unique();
            $table->string('requester_type', 20)->default('customer'); // customer; seller in phase 2
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name', 120);
            $table->string('requester_email', 191);
            $table->string('category', 40);
            $table->string('subject', 150);
            $table->string('status', 20)->default('open');
            $table->string('priority', 20)->default('normal');
            // Order reference without a foreign key: Helpdesk does not own orders
            $table->ulid('order_id')->nullable()->index();
            $table->string('order_number', 40)->nullable();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
            $table->index(['user_id', 'status']);
            $table->index(['assigned_admin_id', 'status']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('author_type', 20); // requester, staff, system
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index(['support_ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
