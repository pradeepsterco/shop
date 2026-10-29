<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Standalone aggregates table (zero alterations to 'products' table)
        Schema::create('review_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('reviewable_type', 64);
            $table->unsignedBigInteger('reviewable_id');
            $table->decimal('rating_avg', 3, 2)->default(0.00);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('star_1')->default(0);
            $table->unsignedInteger('star_2')->default(0);
            $table->unsignedInteger('star_3')->default(0);
            $table->unsignedInteger('star_4')->default(0);
            $table->unsignedInteger('star_5')->default(0);
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['reviewable_type', 'reviewable_id'], 'uq_review_summaries');
            $table->index(['reviewable_type', 'rating_avg']);
        });

        // 2. Customer reviews table
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('reviewable_type', 64)->default('product');
            $table->unsignedBigInteger('reviewable_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name', 120);
            $table->unsignedTinyInteger('rating'); // 1 to 5
            $table->string('title', 120)->nullable();
            $table->text('comment');
            $table->boolean('is_verified_purchase')->default(true);
            $table->string('status', 20)->default('approved'); // approved, hidden, rejected

            // Soft references avoiding hard cross-module foreign keys
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('order_item_id', 64)->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->timestamps();

            // Exactly 1 review per active user per product
            $table->unique(['reviewable_type', 'reviewable_id', 'user_id'], 'uq_user_product_review');
            $table->index(['reviewable_type', 'reviewable_id', 'status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('review_summaries');
    }
};
