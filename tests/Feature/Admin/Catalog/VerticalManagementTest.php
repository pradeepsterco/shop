<?php

use App\Models\Admin;
use App\Models\Category;
use App\Models\Vertical;
use Livewire\Livewire;

test('guest is redirected when visiting verticals', function () {
    $this->get(route('admin.catalog.verticals'))
        ->assertRedirect(route('admin.login'));
});

test('admin can view verticals list', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $cat = Category::create(['name' => 'Topwear']);
    Vertical::create([
        'category_id' => $cat->id,
        'name' => 'T-Shirt',
        'hsn_code' => '6109',
        'gst_rate' => 5.00,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.verticals')
        ->assertSee('T-Shirt')
        ->assertSee('6109');
});

test('admin can create vertical under a leaf category', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $leaf = Category::create(['name' => 'Mobiles']);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.verticals')
        ->call('openCreateModal')
        ->set('name', 'Smartphone')
        ->set('category_id', $leaf->id)
        ->set('hsn_code', '8517')
        ->set('gst_rate', '18.00')
        ->call('saveVertical')
        ->assertHasNoErrors();

    $vertical = Vertical::where('name', 'Smartphone')->first();
    expect($vertical)->not->toBeNull()
        ->and($vertical->category_id)->toBe($leaf->id)
        ->and($vertical->hsn_code)->toBe('8517')
        ->and($vertical->gst_rate)->toBe(18.0)
        ->and($vertical->slug)->toBe('smartphone');
});

test('admin cannot create vertical under a category with subcategories', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $parent = Category::create(['name' => 'Fashion']);
    Category::create(['name' => 'Men', 'parent_id' => $parent->id]);

    expect($parent->canHaveVerticals())->toBeFalse();

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.verticals')
        ->call('openCreateModal')
        ->set('name', 'General Apparel')
        ->set('category_id', $parent->id)
        ->set('hsn_code', '6200')
        ->set('gst_rate', '12.00')
        ->call('saveVertical')
        ->assertHasErrors(['category_id']);
});

test('admin can toggle vertical active status', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $leaf = Category::create(['name' => 'Bags']);
    $vertical = Vertical::create([
        'category_id' => $leaf->id,
        'name' => 'Backpacks',
        'hsn_code' => '4202',
        'gst_rate' => 18.00,
        'is_active' => true,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.verticals')
        ->call('toggleActive', $vertical->id);

    expect($vertical->fresh()->is_active)->toBeFalse();
});
