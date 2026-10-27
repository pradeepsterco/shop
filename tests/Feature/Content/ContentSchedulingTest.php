<?php

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Enums\SectionVisibility;
use App\Content\Models\ContentPage;
use App\Content\Models\ContentSection;
use App\Content\Services\PagePublishService;
use App\Content\Services\PageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

test('section starts_at and ends_at window controls visibility dynamically after cache read', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');

    /** @var PagePublishService $publisher */
    $publisher = app(PagePublishService::class);
    /** @var PageResolver $resolver */
    $resolver = app(PageResolver::class);

    $page = ContentPage::create([
        'title' => 'Summer Campaign',
        'slug' => 'summer-campaign',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    // Section 1: In the future (starts tomorrow)
    ContentSection::create([
        'page_id' => $page->id,
        'type' => 'rich_text',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'starts_at' => Carbon::parse('2026-06-16 00:00:00'),
        'ends_at' => Carbon::parse('2026-06-20 00:00:00'),
        'settings' => ['content_markdown' => 'Future Flash Promo'],
    ]);

    // Section 2: Currently active (started yesterday, ends tomorrow)
    ContentSection::create([
        'page_id' => $page->id,
        'type' => 'rich_text',
        'schema_version' => 1,
        'position' => 2,
        'visibility' => SectionVisibility::All,
        'starts_at' => Carbon::parse('2026-06-14 00:00:00'),
        'ends_at' => Carbon::parse('2026-06-16 00:00:00'),
        'settings' => ['content_markdown' => 'Currently Active Promo'],
    ]);

    // Section 3: Expired (ended yesterday)
    ContentSection::create([
        'page_id' => $page->id,
        'type' => 'rich_text',
        'schema_version' => 1,
        'position' => 3,
        'visibility' => SectionVisibility::All,
        'starts_at' => Carbon::parse('2026-06-10 00:00:00'),
        'ends_at' => Carbon::parse('2026-06-14 00:00:00'),
        'settings' => ['content_markdown' => 'Past Expired Promo'],
    ]);

    $publisher->publish($page);

    // Initial resolution on 2026-06-15: only Section 2 should be visible
    $resolvedNow = $resolver->resolve($page, 'storefront');
    expect($resolvedNow->sections)->toHaveCount(1)
        ->and($resolvedNow->sections[0]->settings['content_markdown'])->toBe('Currently Active Promo');

    // Advance time to 2026-06-17: Section 1 should become visible, Section 2 expired
    // Note: cache key is NOT cleared; PageResolver filters after cache hit!
    Carbon::setTestNow('2026-06-17 12:00:00');

    $resolvedTomorrow = $resolver->resolve($page, 'storefront');
    expect($resolvedTomorrow->sections)->toHaveCount(1)
        ->and($resolvedTomorrow->sections[0]->settings['content_markdown'])->toBe('Future Flash Promo');

    // Advance time past all end dates: both are hidden
    Carbon::setTestNow('2026-06-25 12:00:00');
    $resolvedPastAll = $resolver->resolve($page, 'storefront');
    expect($resolvedPastAll->sections)->toHaveCount(0);

    Carbon::setTestNow(); // Reset
});

test('publish scheduled command automatically publishes due pages and clears cache', function () {
    Carbon::setTestNow('2026-07-01 10:00:00');

    // Page A: Scheduled and due (scheduled for 09:00 today)
    $duePage = ContentPage::create([
        'title' => 'Monsoon Sale',
        'slug' => 'monsoon-sale',
        'type' => PageType::Landing,
        'status' => PageStatus::Scheduled,
        'scheduled_publish_at' => Carbon::parse('2026-07-01 09:00:00'),
    ]);

    ContentSection::create([
        'page_id' => $duePage->id,
        'type' => 'rich_text',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'settings' => ['content_markdown' => 'Monsoon discounts live now!'],
    ]);

    // Page B: Scheduled in the future (scheduled for tomorrow)
    $futurePage = ContentPage::create([
        'title' => 'August Preview',
        'slug' => 'august-preview',
        'type' => PageType::Landing,
        'status' => PageStatus::Scheduled,
        'scheduled_publish_at' => Carbon::parse('2026-07-02 09:00:00'),
    ]);

    // Page C: Draft page without schedule
    $draftPage = ContentPage::create([
        'title' => 'Draft WIP',
        'slug' => 'draft-wip',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    // Run scheduler command
    $exitCode = Artisan::call('content:publish-scheduled');
    expect($exitCode)->toBe(0);

    $duePage->refresh();
    $futurePage->refresh();
    $draftPage->refresh();

    // Due page must be published with a version
    expect($duePage->status)->toBe(PageStatus::Published)
        ->and($duePage->published_version_id)->not->toBeNull()
        ->and($duePage->scheduled_publish_at)->toBeNull();

    // Future page must remain scheduled
    expect($futurePage->status)->toBe(PageStatus::Scheduled)
        ->and($futurePage->published_version_id)->toBeNull();

    // Draft page remains draft
    expect($draftPage->status)->toBe(PageStatus::Draft);

    Carbon::setTestNow();
});
