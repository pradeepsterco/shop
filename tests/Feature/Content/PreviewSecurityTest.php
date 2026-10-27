<?php

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Enums\SectionVisibility;
use App\Content\Models\ContentPage;
use App\Content\Models\ContentSection;
use App\Models\Admin;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('permissions:sync');
});

test('preview route requires valid signed url and rejects unsigned requests with 403', function () {
    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW);

    $page = ContentPage::create([
        'title' => 'Secret Draft Launch',
        'slug' => 'secret-draft',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    // Unsigned request
    $unsignedUrl = route('admin.pages.preview', ['page' => $page]);

    $this->actingAs($admin, 'admin')
        ->get($unsignedUrl)
        ->assertForbidden();
});

test('preview route rejects tampered signature with 403', function () {
    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW);

    $page = ContentPage::create([
        'title' => 'Tamper Test',
        'slug' => 'tamper-test',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    $validSignedUrl = URL::signedRoute('admin.pages.preview', ['page' => $page]);
    $tamperedUrl = $validSignedUrl.'&tampered=true';

    $this->actingAs($admin, 'admin')
        ->get($tamperedUrl)
        ->assertForbidden();
});

test('preview route rejects expired temporary signed url with 403', function () {
    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW);

    $page = ContentPage::create([
        'title' => 'Expired Test',
        'slug' => 'expired-test',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    // Generate signed URL expiring 10 minutes ago
    Carbon::setTestNow('2026-05-01 10:00:00');
    $expiredUrl = URL::temporarySignedRoute(
        'admin.pages.preview',
        now()->addMinutes(5),
        ['page' => $page]
    );

    // Fast-forward past expiry
    Carbon::setTestNow('2026-05-01 10:15:00');

    $this->actingAs($admin, 'admin')
        ->get($expiredUrl)
        ->assertForbidden();

    Carbon::setTestNow();
});

test('preview route renders draft page for authorized admin with valid signed url and noindex header', function () {
    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW);

    $page = ContentPage::create([
        'title' => 'Sneak Peek Landing',
        'slug' => 'sneak-peek',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    ContentSection::create([
        'page_id' => $page->id,
        'type' => 'rich_text',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'settings' => ['content_markdown' => 'Confidential Pre-Release Offer'],
    ]);

    // No 'device' param in the signed URL — device is now client-side only (Alpine).
    $signedUrl = URL::temporarySignedRoute(
        'admin.pages.preview',
        now()->addMinutes(30),
        ['page' => $page]
    );

    $response = $this->actingAs($admin, 'admin')->get($signedUrl);

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('Sneak Peek Landing')
        ->assertSee('Confidential Pre-Release Offer')
        ->assertSee('PREVIEW MODE')
        // Viewport toggle must be present as buttons, not URL-navigating links.
        ->assertSee('Desktop')
        ->assertSee('Mobile')
        // Crucially: no href with device query params that would break the signature.
        ->assertDontSee('device=desktop')
        ->assertDontSee('device=mobile');
});

test('preview viewport toggle uses alpine buttons not url links so signature stays intact', function () {
    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW);

    $page = ContentPage::create([
        'title' => 'Toggle Test Page',
        'slug' => 'toggle-test',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'admin.pages.preview',
        now()->addMinutes(30),
        ['page' => $page]
    );

    $html = $this->actingAs($admin, 'admin')->get($signedUrl)->assertOk()->getContent();

    // The toggle must be <button> elements, never <a href="...?device=..."> links.
    expect($html)
        ->toContain('setMode(\'desktop\')')
        ->toContain('setMode(\'mobile\')')
        ->not->toContain('device=desktop')
        ->not->toContain('device=mobile')
        // Alpine data attribute must be present (client-side state).
        ->toContain('x-data');
});

test('preview and storefront final page include theme switcher and support light dark system mode', function () {
    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW);

    $page = ContentPage::create([
        'title' => 'Theme Support Test Page',
        'slug' => 'theme-support-test',
        'type' => PageType::Static,
        'status' => PageStatus::Published,
    ]);

    ContentSection::create([
        'page_id' => $page->id,
        'type' => 'rich_text',
        'schema_version' => 1,
        'position' => 1,
        'visibility' => SectionVisibility::All,
        'settings' => [
            'content' => "# Contact Support\n\nReach out to customer care anytime.",
        ],
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'admin.pages.preview',
        now()->addMinutes(30),
        ['page' => $page]
    );

    // 1. Preview page includes theme switcher and theme scripts
    $previewHtml = $this->actingAs($admin, 'admin')->get($signedUrl)->assertOk()->getContent();
    expect($previewHtml)
        ->toContain('setAppearance(\'light\')')
        ->toContain('setAppearance(\'dark\')')
        ->toContain('setAppearance(\'system\')')
        ->toContain('html.is-preview-frame.dark')
        ->toContain('html.is-preview-frame:not(.dark)');

    // 2. Final storefront page includes theme switcher and visible high-contrast prose styles
    $finalHtml = $this->get('/pages/theme-support-test')->assertOk()->getContent();
    expect($finalHtml)
        ->toContain('setAppearance(\'light\')')
        ->toContain('setAppearance(\'dark\')')
        ->toContain('setAppearance(\'system\')')
        ->toContain('Contact Support')
        ->toContain('dark:text-zinc-100');
});
