<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index storage for the database search engine.
     */
    public function up(): void
    {
        Schema::create('search_documents', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained('products')->cascadeOnDelete();
            $table->string('title');
            $table->text('keywords');
            $table->boolean('in_stock')->default(false);
            $table->json('document');
            $table->timestamps();

            $table->index('in_stock');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_documents');
    }
};
