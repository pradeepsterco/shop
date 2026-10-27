<?php

use App\Content\Enums\LinkType;
use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Enums\SectionVisibility;
use App\Content\Models\ContentMedia;
use App\Content\Models\ContentPage;
use App\Content\Models\ContentSection;
use App\Content\Models\ContentSectionItem;
use App\Content\Services\PagePublishService;
use App\Content\Services\PageResolver;
use App\Enums\BrandStatus;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('page resolver generates strongly typed DTOs for page, sections, items, media, and links', function () {
    /** @var PagePublishService $publisher */
    $publisher = app(PagePublishService::class);
    /** @var PageResolver $resolver */
    $resolver = app(PageResolver::class);

    $desktopMedia = ContentMedia::create([
        'filename' => 'desktop.png',
        'disk' => 'public',
        'path' => 'content-media/desktop.png',
        'variants' => ['webp' => 'content-media/desktop.webp'],
        'mime_type' => 'image/png',
        'size_bytes' => 5000,
        'width' => 1200,
        'height' => 600,
        'alt_text' => 'Hero Banner',
    ]);

    $category = Category::create([
        'name' => 'Watches',
        'slug' => 'watches',
        'is_active' => true,
    ]);

    $page = ContentPage::create([
        'title' => 'Landing Watches',
        'slug' => 'watches-landing',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
        'seo_title' => 'Luxury Watches',
        'seo_description' => 'Best collection of watches',
    ]);

    $section = ContentSection::create([
        'page_id' => $page->id,
        'type' => 'hero_carousel',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'settings' => ['autoplay' => true, 'autoplay_interval_ms' => 4000],
    ]);

    ContentSectionItem::create([
        'section_id' => $section->id,
        'position' => 1,
        'desktop_media_id' => $desktopMedia->id,
        'mobile_media_id' => null, // Test mobile fallback
        'link_type' => LinkType::Category,
        'link_id' => (string) $category->id,
        'settings' => [
            'headline' => 'Luxury Timepieces',
            'cta_label' => 'Explore',
        ],
    ]);

    $publisher->publish($page);

    $resolved = $resolver->resolve($page, 'storefront');

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($page->id)
        ->and($resolved->title)->toBe('Landing Watches')
        ->and($resolved->slug)->toBe('watches-landing')
        ->and($resolved->sections)->toHaveCount(1);

    $sec = $resolved->sections[0];
    expect($sec->type)->toBe('hero_carousel')
        ->and($sec->isEager)->toBeTrue()
        ->and($sec->items)->toHaveCount(1);

    $item = $sec->items[0];
    expect($item->desktopMedia)->not->toBeNull()
        ->and($item->desktopMedia->alt)->toBe('Hero Banner')
        ->and($item->desktopMedia->webpUrl)->toContain('.webp')
        // Mobile fallback verification
        ->and($item->mobileMedia)->not->toBeNull()
        ->and($item->mobileMedia->url)->toBe($item->desktopMedia->url)
        // Link resolution verification
        ->and($item->link)->not->toBeNull()
        ->and($item->link->url)->toContain('watches')
        ->and($item->link->isExternal)->toBeFalse();
});

test('link resolver handles categories, brands, products, static pages, searches, and external URLs', function () {
    /** @var PageResolver $resolver */
    $resolver = app(PageResolver::class);

    $cat = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);
    $brand = Brand::create(['name' => 'Sony', 'slug' => 'sony', 'status' => BrandStatus::Approved]);
    $staticPage = ContentPage::create(['title' => 'FAQ', 'slug' => 'faq', 'type' => PageType::Static, 'status' => PageStatus::Published]);

    $page = ContentPage::create(['title' => 'Links Showcase', 'slug' => 'links-showcase', 'type' => PageType::Landing, 'status' => PageStatus::Draft]);

    $section = ContentSection::create([
        'page_id' => $page->id,
        'type' => 'promo_card_carousel',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'settings' => ['cards_visible' => 4],
    ]);

    // 1. Category link
    ContentSectionItem::create([
        'section_id' => $section->id,
        'position' => 1,
        'link_type' => LinkType::Category,
        'link_id' => (string) $cat->id,
        'settings' => ['title' => 'Category Link'],
    ]);

    // 2. Brand link
    ContentSectionItem::create([
        'section_id' => $section->id,
        'position' => 2,
        'link_type' => LinkType::Brand,
        'link_id' => (string) $brand->id,
        'settings' => ['title' => 'Brand Link'],
    ]);

    // 3. Static page link
    ContentSectionItem::create([
        'section_id' => $section->id,
        'position' => 3,
        'link_type' => LinkType::Page,
        'link_id' => 'faq',
        'settings' => ['title' => 'Page Link'],
    ]);

    // 4. Search link
    ContentSectionItem::create([
        'section_id' => $section->id,
        'position' => 4,
        'link_type' => LinkType::Search,
        'link_url' => 'headphones',
        'settings' => ['title' => 'Search Link'],
    ]);

    // 5. External link
    ContentSectionItem::create([
        'section_id' => $section->id,
        'position' => 5,
        'link_type' => LinkType::Url,
        'link_url' => 'https://example.com/blog',
        'settings' => ['title' => 'External Link'],
    ]);

    $resolved = $resolver->resolve($page, 'preview');
    $items = $resolved->sections[0]->items;

    // Verify 1: Category
    expect($items[0]->link->url)->toContain('electronics')
        ->and($items[0]->link->isExternal)->toBeFalse();

    // Verify 2: Brand
    expect($items[1]->link->url)->toContain('sony')
        ->and($items[1]->link->isExternal)->toBeFalse();

    // Verify 3: Static Page
    expect($items[2]->link->url)->toContain('/pages/faq')
        ->and($items[2]->link->isExternal)->toBeFalse();

    // Verify 4: Search
    expect($items[3]->link->url)->toContain('search=headphones')
        ->and($items[3]->link->isExternal)->toBeFalse();

    // Verify 5: External
    expect($items[4]->link->url)->toBe('https://example.com/blog')
        ->and($items[4]->link->isExternal)->toBeTrue()
        ->and($items[4]->link->target)->toBe('_blank')
        ->and($items[4]->link->rel)->toContain('noopener');
});

test('link resolution survives category and brand slug changes because link_id points to ID', function () {
    /** @var PageResolver $resolver */
    $resolver = app(PageResolver::class);

    $cat = Category::create(['name' => 'Gadgets', 'slug' => 'gadgets-v1', 'is_active' => true]);

    $page = ContentPage::create(['title' => 'Slug Test', 'slug' => 'slug-test', 'type' => PageType::Landing, 'status' => PageStatus::Draft]);
    $section = ContentSection::create([
        'page_id' => $page->id,
        'type' => 'category_nav',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'settings' => ['columns' => 6],
    ]);

    ContentSectionItem::create([
        'section_id' => $section->id,
        'position' => 1,
        'link_type' => LinkType::Category,
        'link_id' => (string) $cat->id,
        'settings' => ['label' => 'Gadgets'],
    ]);

    $resolvedBefore = $resolver->resolve($page, 'preview');
    expect($resolvedBefore->sections[0]->items[0]->link->url)->toContain('gadgets-v1');

    // Rename category slug
    $cat->update(['slug' => 'smart-gadgets-2026']);

    $resolvedAfter = $resolver->resolve($page, 'preview');
    expect($resolvedAfter->sections[0]->items[0]->link->url)->toContain('smart-gadgets-2026');
});

test('countdown section hides or flags disabled when end_time has expired', function () {
    /** @var PagePublishService $publisher */
    $publisher = app(PagePublishService::class);
    /** @var PageResolver $resolver */
    $resolver = app(PageResolver::class);

    $page = ContentPage::create(['title' => 'Sale', 'slug' => 'countdown-sale', 'type' => PageType::Landing, 'status' => PageStatus::Draft]);

    // Section A: expired_behavior = hide
    ContentSection::create([
        'page_id' => $page->id,
        'type' => 'countdown',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'settings' => [
            'headline' => 'Expired Flash Deal',
            'end_time' => Carbon::now()->subHour()->toIso8601String(),
            'expired_behavior' => 'hide',
        ],
    ]);

    // Section B: active countdown
    ContentSection::create([
        'page_id' => $page->id,
        'type' => 'countdown',
        'schema_version' => 1,
        'position' => 2,
        'visibility' => SectionVisibility::All,
        'settings' => [
            'headline' => 'Active Deal',
            'end_time' => Carbon::now()->addHours(5)->toIso8601String(),
            'expired_behavior' => 'hide',
        ],
    ]);

    $publisher->publish($page);

    $resolved = $resolver->resolve($page, 'storefront');

    // Expired section should be omitted from storefront!
    expect($resolved->sections)->toHaveCount(1)
        ->and($resolved->sections[0]->settings['headline'])->toBe('Active Deal');
});
