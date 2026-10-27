<?php

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Enums\SectionVisibility;
use App\Content\Models\ContentPage;
use App\Content\Models\ContentSection;
use App\Content\Services\PagePublishService;
use App\Content\Services\PageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

test('publishing a home page demotes any existing published home page to draft and clears cache', function () {
    /** @var PagePublishService $publisher */
    $publisher = app(PagePublishService::class);

    // Create Home Page A
    $homeA = ContentPage::create([
        'title' => 'Home Page V1',
        'slug' => 'home-a',
        'type' => PageType::Home,
        'status' => PageStatus::Draft,
    ]);

    ContentSection::create([
        'page_id' => $homeA->id,
        'type' => 'rich_text',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'settings' => ['content_markdown' => 'Hello from Home A'],
    ]);

    $publisher->publish($homeA);
    $homeA->refresh();

    expect($homeA->status)->toBe(PageStatus::Published)
        ->and($homeA->published_version_id)->not->toBeNull();

    // Cache dummy key to verify invalidation
    $cacheKey = "content.page.{$homeA->slug}.published";
    Cache::put($cacheKey, ['cached' => true], 3600);
    expect(Cache::has($cacheKey))->toBeTrue();

    // Create Home Page B and publish it
    $homeB = ContentPage::create([
        'title' => 'Home Page V2',
        'slug' => 'home-b',
        'type' => PageType::Home,
        'status' => PageStatus::Draft,
    ]);

    ContentSection::create([
        'page_id' => $homeB->id,
        'type' => 'rich_text',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'settings' => ['content_markdown' => 'Hello from Home B'],
    ]);

    $publisher->publish($homeB);

    $homeA->refresh();
    $homeB->refresh();

    // Home B is now Published, Home A is demoted to Draft
    expect($homeB->status)->toBe(PageStatus::Published)
        ->and($homeA->status)->toBe(PageStatus::Draft)
        ->and(Cache::has($cacheKey))->toBeFalse();
});

test('draft changes are isolated from storefront until published', function () {
    /** @var PagePublishService $publisher */
    $publisher = app(PagePublishService::class);
    /** @var PageResolver $resolver */
    $resolver = app(PageResolver::class);

    $page = ContentPage::create([
        'title' => 'Landing Page',
        'slug' => 'test-landing',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    $section = ContentSection::create([
        'page_id' => $page->id,
        'type' => 'rich_text',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'settings' => ['content_markdown' => 'Original Published Content'],
    ]);

    $publisher->publish($page);

    // Initial resolved storefront output
    $resolvedStorefront = $resolver->resolve($page, 'storefront');
    expect($resolvedStorefront)->not->toBeNull()
        ->and($resolvedStorefront->sections[0]->settings['content_markdown'])->toBe('Original Published Content');

    // Admin modifies live draft section
    $section->update([
        'settings' => ['content_markdown' => 'Unpublished Work In Progress Edit'],
    ]);

    // Storefront still sees the published version!
    $afterEditStorefront = $resolver->resolve($page, 'storefront');
    expect($afterEditStorefront->sections[0]->settings['content_markdown'])->toBe('Original Published Content');

    // Admin preview sees the draft changes!
    $previewData = $resolver->resolve($page, 'preview');
    expect($previewData->sections[0]->settings['content_markdown'])->toBe('Unpublished Work In Progress Edit');

    // Now publish again
    $publisher->publish($page);

    // Storefront now reflects the second version
    $secondPublishStorefront = $resolver->resolve($page, 'storefront');
    expect($secondPublishStorefront->sections[0]->settings['content_markdown'])->toBe('Unpublished Work In Progress Edit');
});

test('rollback restores draft sections and items from prior published version snapshot', function () {
    /** @var PagePublishService $publisher */
    $publisher = app(PagePublishService::class);

    $page = ContentPage::create([
        'title' => 'Versioned Page',
        'slug' => 'versioned-page',
        'type' => PageType::Static,
        'status' => PageStatus::Draft,
    ]);

    // Version 1 setup
    $section1 = ContentSection::create([
        'page_id' => $page->id,
        'type' => 'rich_text',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'settings' => ['content_markdown' => 'Version 1 Content'],
    ]);

    $version1 = $publisher->publish($page);
    expect($version1->version_number)->toBe(1);

    // Version 2 setup
    $section1->update(['settings' => ['content_markdown' => 'Version 2 Content']]);
    $version2 = $publisher->publish($page);
    expect($version2->version_number)->toBe(2);

    // Version 3 setup
    $section1->update(['settings' => ['content_markdown' => 'Version 3 Content']]);
    $version3 = $publisher->publish($page);
    expect($version3->version_number)->toBe(3);

    // Rollback to Version 1
    $publisher->rollback($page, $version1);

    $page->load('sections');
    expect($page->sections)->toHaveCount(1)
        ->and($page->sections->first()->settings['content_markdown'])->toBe('Version 1 Content');
});

test('rollback rejects version snapshot belonging to another page', function () {
    /** @var PagePublishService $publisher */
    $publisher = app(PagePublishService::class);

    $pageA = ContentPage::create(['title' => 'A', 'slug' => 'page-a', 'type' => PageType::Landing, 'status' => PageStatus::Draft]);
    $pageB = ContentPage::create(['title' => 'B', 'slug' => 'page-b', 'type' => PageType::Landing, 'status' => PageStatus::Draft]);

    $vA = $publisher->publish($pageA);

    expect(fn () => $publisher->rollback($pageB, $vA))
        ->toThrow(DomainException::class);
});
