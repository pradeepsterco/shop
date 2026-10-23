<?php

use App\Models\Admin;
use App\Models\Category;
use App\Models\Vertical;
use Livewire\Livewire;

test('guest is redirected to admin login when visiting categories', function () {
    $this->get(route('admin.catalog.categories'))
        ->assertRedirect(route('admin.login'));
});

test('admin can view category list and hierarchy', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $parent = Category::create(['name' => 'Fashion', 'sort_order' => 1]);
    $child = Category::create(['name' => 'Men', 'parent_id' => $parent->id, 'sort_order' => 1]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.categories')
        ->assertSee('Category Hierarchy')
        ->assertSee('Fashion')
        ->assertSee('Men');
});

test('admin can create root category and subcategory', function () {
    $admin = Admin::factory()->superAdmin()->create();

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.categories')
        ->call('openCreateModal', null)
        ->set('name', 'Electronics')
        ->set('sort_order', 2)
        ->call('saveCategory')
        ->assertHasNoErrors();

    $root = Category::where('name', 'Electronics')->first();
    expect($root)->not->toBeNull()
        ->and($root->parent_id)->toBeNull()
        ->and($root->slug)->toBe('electronics');

    // Create subcategory
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.categories')
        ->call('openCreateModal', $root->id)
        ->set('name', 'Laptops')
        ->set('sort_order', 1)
        ->call('saveCategory')
        ->assertHasNoErrors();

    $sub = Category::where('name', 'Laptops')->first();
    expect($sub)->not->toBeNull()
        ->and($sub->parent_id)->toBe($root->id)
        ->and($sub->slug)->toBe('laptops');
});

test('admin cannot add subcategory to a category that has verticals assigned', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $category = Category::create(['name' => 'Shoes']);
    Vertical::create([
        'category_id' => $category->id,
        'name' => 'Running Shoes',
        'hsn_code' => '6404',
        'gst_rate' => 12.00,
    ]);

    expect($category->canHaveChildren())->toBeFalse();

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.categories')
        ->call('openCreateModal', $category->id)
        ->set('name', 'Sneakers')
        ->call('saveCategory')
        ->assertHasErrors(['parent_id']);
});

test('deactivating a category deactivates all its descendants', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $root = Category::create(['name' => 'Home', 'is_active' => true]);
    $sub = Category::create(['name' => 'Kitchen', 'parent_id' => $root->id, 'is_active' => true]);
    $leaf = Category::create(['name' => 'Cookware', 'parent_id' => $sub->id, 'is_active' => true]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.categories')
        ->call('toggleActive', $root->id);

    expect($root->fresh()->is_active)->toBeFalse()
        ->and($sub->fresh()->is_active)->toBeFalse()
        ->and($leaf->fresh()->is_active)->toBeFalse();
});
