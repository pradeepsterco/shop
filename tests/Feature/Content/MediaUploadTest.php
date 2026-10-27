<?php

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Enums\SectionVisibility;
use App\Content\Models\ContentMedia;
use App\Content\Models\ContentPage;
use App\Content\Models\ContentSection;
use App\Content\Models\ContentSectionItem;
use App\Content\Services\MediaUploadService;
use App\Content\Services\PagePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('media upload accepts PNG and generates WebP variant with measured dimensions', function () {
    Storage::fake('public');

    /** @var MediaUploadService $service */
    $service = app(MediaUploadService::class);

    $file = UploadedFile::fake()->image('banner.png', 800, 400);

    $media = $service->upload($file, 'Homepage Banner');

    expect($media)->toBeInstanceOf(ContentMedia::class)
        ->and($media->filename)->toBe('banner.png')
        ->and($media->alt_text)->toBe('Homepage Banner')
        ->and($media->width)->toBe(800)
        ->and($media->height)->toBe(400)
        ->and($media->disk)->toBe('public');

    Storage::disk('public')->assertExists($media->path);

    if (function_exists('imagewebp')) {
        expect($media->variants)->toHaveKey('webp');
        Storage::disk('public')->assertExists($media->variants['webp']);
        expect($media->webpUrl())->toContain('.webp');
    }
});

test('media upload rejects SVG files with validation exception', function () {
    Storage::fake('public');

    /** @var MediaUploadService $service */
    $service = app(MediaUploadService::class);

    $svgFile = UploadedFile::fake()->create('vector.svg', 10, 'image/svg+xml');

    expect(fn () => $service->upload($svgFile, 'Vector image'))
        ->toThrow(ValidationException::class);
});

test('media upload rejects oversized files exceeding 5MB', function () {
    Storage::fake('public');

    /** @var MediaUploadService $service */
    $service = app(MediaUploadService::class);

    // 6MB image file
    $oversizedFile = UploadedFile::fake()->create('large.jpg', 6144, 'image/jpeg');

    expect(fn () => $service->upload($oversizedFile, 'Oversized image'))
        ->toThrow(ValidationException::class);
});

test('media deletion is blocked when media is in use by live section items', function () {
    Storage::fake('public');

    /** @var MediaUploadService $service */
    $service = app(MediaUploadService::class);

    $file = UploadedFile::fake()->image('used.png', 400, 200);
    $media = $service->upload($file, 'Used in draft');

    $page = ContentPage::create([
        'title' => 'Landing Page',
        'slug' => 'landing-test',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    $section = ContentSection::create([
        'page_id' => $page->id,
        'type' => 'single_banner',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'settings' => [],
    ]);

    ContentSectionItem::create([
        'section_id' => $section->id,
        'position' => 1,
        'desktop_media_id' => $media->id,
        'settings' => [],
    ]);

    $usages = $service->findMediaUsages($media);
    expect($usages)->not->toBeEmpty()
        ->and($usages[0])->toContain('Landing Page');

    expect(fn () => $service->delete($media))
        ->toThrow(DomainException::class);

    // Media file and record remain intact
    expect(ContentMedia::find($media->id))->not->toBeNull();
    Storage::disk('public')->assertExists($media->path);
});

test('media deletion is blocked when media is referenced in published version snapshot', function () {
    Storage::fake('public');

    /** @var MediaUploadService $service */
    $service = app(MediaUploadService::class);
    /** @var PagePublishService $publisher */
    $publisher = app(PagePublishService::class);

    $file = UploadedFile::fake()->image('published-media.png', 600, 300);
    $media = $service->upload($file, 'Media in published snapshot');

    $page = ContentPage::create([
        'title' => 'Sale Hub',
        'slug' => 'sale-hub',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    $section = ContentSection::create([
        'page_id' => $page->id,
        'type' => 'single_banner',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'settings' => [],
    ]);

    $item = ContentSectionItem::create([
        'section_id' => $section->id,
        'position' => 1,
        'desktop_media_id' => $media->id,
        'settings' => [],
    ]);

    // Publish page (creates immutable snapshot containing media id)
    $publisher->publish($page);

    // Now remove media from the live draft item
    $item->update(['desktop_media_id' => null]);

    // Media is no longer in live section items, but IS in published page version snapshot!
    $usages = $service->findMediaUsages($media);
    expect($usages)->not->toBeEmpty()
        ->and($usages[0])->toContain('Sale Hub (published version #1)');

    expect(fn () => $service->delete($media))
        ->toThrow(DomainException::class);
});

test('unused media can be safely deleted and files are cleaned up from disk', function () {
    Storage::fake('public');

    /** @var MediaUploadService $service */
    $service = app(MediaUploadService::class);

    $file = UploadedFile::fake()->image('temporary.png', 300, 150);
    $media = $service->upload($file, 'Unused asset');
    $path = $media->path;
    $webpPath = $media->variants['webp'] ?? null;

    Storage::disk('public')->assertExists($path);
    if ($webpPath) {
        Storage::disk('public')->assertExists($webpPath);
    }

    $usages = $service->findMediaUsages($media);
    expect($usages)->toBeEmpty();

    $service->delete($media);

    expect(ContentMedia::find($media->id))->toBeNull();
    Storage::disk('public')->assertMissing($path);
    if ($webpPath) {
        Storage::disk('public')->assertMissing($webpPath);
    }
});
