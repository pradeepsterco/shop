<?php

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Models\ContentMedia;
use App\Content\Models\ContentPage;
use App\Models\Admin;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('permissions:sync');
});

test('unauthenticated guest is redirected to admin login', function () {
    $this->get(route('admin.pages.index'))
        ->assertRedirect(route('admin.login'));
});

test('admin without pages.view is denied access with 403', function () {
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin')
        ->get(route('admin.pages.index'))
        ->assertForbidden();
});

test('admin with pages.view can view the pages index list', function () {
    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW);

    ContentPage::create([
        'title' => 'Sample Index Page',
        'slug' => 'sample-index-page',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.pages.index'))
        ->assertOk()
        ->assertSee('Sample Index Page');
});

test('admin without pages.manage cannot access page builder', function () {
    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW); // has view but not manage

    $page = ContentPage::create([
        'title' => 'Builder Test Page',
        'slug' => 'builder-test-page',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.pages.builder', $page))
        ->assertForbidden();
});

test('admin with pages.manage can access page builder', function () {
    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW);
    $admin->givePermissionTo(AdminPermissions::PAGES_MANAGE);

    $page = ContentPage::create([
        'title' => 'Builder Test Page',
        'slug' => 'builder-test-page',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.pages.builder', $page))
        ->assertOk()
        ->assertSee('Builder Test Page');
});

test('admin with pages.manage but without pages.publish cannot trigger publish action', function () {
    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW);
    $admin->givePermissionTo(AdminPermissions::PAGES_MANAGE);
    // Note: pages.publish NOT granted

    $page = ContentPage::create([
        'title' => 'Publish Guard Test',
        'slug' => 'publish-guard-test',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    $this->actingAs($admin, 'admin');

    Livewire::test('pages::admin.content.page-builder', ['page' => $page])
        ->call('publishNow')
        ->assertForbidden();
});

test('admin with pages.publish can publish a page', function () {
    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW);
    $admin->givePermissionTo(AdminPermissions::PAGES_MANAGE);
    $admin->givePermissionTo(AdminPermissions::PAGES_PUBLISH);

    $page = ContentPage::create([
        'title' => 'Authorized Publish Test',
        'slug' => 'auth-publish-test',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    $this->actingAs($admin, 'admin');

    Livewire::test('pages::admin.content.page-builder', ['page' => $page])
        ->call('publishNow')
        ->assertHasNoErrors();

    $page->refresh();
    expect($page->status)->toBe(PageStatus::Published);
});

test('page builder handles add-section, reordering, and media selection', function () {
    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW);
    $admin->givePermissionTo(AdminPermissions::PAGES_MANAGE);

    $page = ContentPage::create([
        'title' => 'Interactive Builder Test',
        'slug' => 'interactive-builder-test',
        'type' => PageType::Landing,
        'status' => PageStatus::Draft,
    ]);

    $media = ContentMedia::create([
        'filename' => 'sample-test.png',
        'disk' => 'public',
        'path' => 'content-media/sample-test.png',
        'mime_type' => 'image/png',
        'size_bytes' => 1024,
        'width' => 800,
        'height' => 400,
        'alt_text' => 'Sample Test Image',
    ]);

    $this->actingAs($admin, 'admin');

    // 1. Add section 1 (single_banner)
    $component = Livewire::test('pages::admin.content.page-builder', ['page' => $page])
        ->call('addSection', 'single_banner')
        ->assertHasNoErrors();

    expect($page->sections()->count())->toBe(1);
    $firstSection = $page->sections()->firstOrFail();
    expect($firstSection->type)->toBe('single_banner');

    // 2. Add section 2 (hero_carousel)
    $component->call('addSection', 'hero_carousel');
    expect($page->sections()->count())->toBe(2);

    $sections = $page->sections()->orderBy('position')->get();
    $sec0 = $sections[0];
    $sec1 = $sections[1];
    expect($sec0->position)->toBe(0)
        ->and($sec1->position)->toBe(1);

    // 3. Reorder: move section 0 down
    $component->call('moveSectionDown', $sec0->id);
    $reordered = $page->sections()->orderBy('position')->get();
    expect($reordered[0]->id)->toBe($sec1->id)
        ->and($reordered[1]->id)->toBe($sec0->id);

    // 4. Reorder: move section 0 back up
    $component->call('moveSectionUp', $sec0->id);
    $restored = $page->sections()->orderBy('position')->get();
    expect($restored[0]->id)->toBe($sec0->id)
        ->and($restored[1]->id)->toBe($sec1->id);

    // 5. Media picker: open picker for item and select media
    $item = $sec0->items()->firstOrFail();
    $component->call('openMediaPicker', $item->id, 'desktop')
        ->call('selectMedia', $media->id);

    $item->refresh();
    expect($item->desktop_media_id)->toBe($media->id);
});

test('create page modal auto-generates slug and allows creating homepage with title Home', function () {
    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW);
    $admin->givePermissionTo(AdminPermissions::PAGES_MANAGE);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.content.pages-index')
        ->call('openCreateModal')
        ->set('newTitle', 'Home')
        ->assertSet('newSlug', 'home')
        ->set('newType', 'home')
        ->assertSet('newSlug', 'home')
        ->call('createPage')
        ->assertHasNoErrors()
        ->assertRedirect();

    $page = ContentPage::where('slug', 'home')->first();
    expect($page)->not->toBeNull()
        ->and($page->title)->toBe('Home')
        ->and($page->type)->toBe(PageType::Home);
});
