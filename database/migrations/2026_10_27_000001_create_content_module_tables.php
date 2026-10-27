<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Reusable Media Library
        Schema::create('content_media', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('disk', 50)->default('public');
            $table->string('path', 500);
            $table->json('variants')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('alt_text', 255);
            $table->timestamps();

            $table->index('mime_type');
            $table->index('created_at');
        });

        // 2. Content Pages
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 150)->unique();
            $table->string('title', 255);
            $table->string('type', 30)->default('landing'); // 'home', 'landing', 'static'
            $table->string('status', 30)->default('draft'); // 'draft', 'scheduled', 'published'
            $table->string('seo_title', 255)->nullable();
            $table->text('seo_description')->nullable();
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestamp('scheduled_publish_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['status', 'scheduled_publish_at']);
        });

        // 3. Page Versions (Immutable JSON snapshots)
        Schema::create('content_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('content_pages')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->foreignId('published_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['page_id', 'version_number']);
        });

        // Add foreign key constraint for published_version_id on content_pages
        Schema::table('content_pages', function (Blueprint $table) {
            $table->foreign('published_version_id')
                ->references('id')
                ->on('content_page_versions')
                ->nullOnDelete();
        });

        // 4. Content Sections
        Schema::create('content_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('content_pages')->cascadeOnDelete();
            $table->string('type', 50);
            $table->unsignedInteger('schema_version')->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->string('visibility', 20)->default('all'); // 'all', 'desktop', 'mobile'
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->json('settings');
            $table->timestamps();

            $table->index(['page_id', 'position']);
            $table->index(['page_id', 'is_hidden']);
        });

        // 5. Content Section Items
        Schema::create('content_section_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('content_sections')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('desktop_media_id')->nullable()->constrained('content_media')->restrictOnDelete();
            $table->foreignId('mobile_media_id')->nullable()->constrained('content_media')->restrictOnDelete();
            $table->string('link_type', 30)->nullable(); // 'category', 'brand', 'product', 'page', 'search', 'url'
            $table->string('link_id', 150)->nullable();
            $table->string('link_url', 1000)->nullable();
            $table->json('settings');
            $table->timestamps();

            $table->index(['section_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_section_items');
        Schema::dropIfExists('content_sections');

        Schema::table('content_pages', function (Blueprint $table) {
            $table->dropForeign(['published_version_id']);
        });

        Schema::dropIfExists('content_page_versions');
        Schema::dropIfExists('content_pages');
        Schema::dropIfExists('content_media');
    }
};
