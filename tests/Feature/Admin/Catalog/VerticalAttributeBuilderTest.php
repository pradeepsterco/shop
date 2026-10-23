<?php

use App\Enums\AttributeType;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Vertical;
use App\Models\VerticalAttribute;
use Livewire\Livewire;

test('admin can view vertical attributes builder', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $cat = Category::create(['name' => 'Topwear']);
    $vertical = Vertical::create([
        'category_id' => $cat->id,
        'name' => 'T-Shirt',
        'hsn_code' => '6109',
        'gst_rate' => 5.00,
    ]);

    VerticalAttribute::create([
        'vertical_id' => $vertical->id,
        'name' => 'Size',
        'code' => 'size',
        'type' => AttributeType::Select,
        'is_variant' => true,
        'is_required' => true,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.vertical-attributes', ['vertical' => $vertical])
        ->assertSee('Attributes for T-Shirt')
        ->assertSee('Size')
        ->assertSee('size');
});

test('admin can create a new attribute with options', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $cat = Category::create(['name' => 'Topwear']);
    $vertical = Vertical::create([
        'category_id' => $cat->id,
        'name' => 'T-Shirt',
        'hsn_code' => '6109',
        'gst_rate' => 5.00,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.vertical-attributes', ['vertical' => $vertical])
        ->call('openCreateModal')
        ->set('name', 'Color')
        ->set('code', 'color')
        ->set('type', 'select')
        ->set('is_variant', true)
        ->set('is_required', true)
        ->set('sort_order', 1)
        ->call('saveAttribute')
        ->assertHasNoErrors();

    $attr = VerticalAttribute::where('vertical_id', $vertical->id)->where('code', 'color')->first();
    expect($attr)->not->toBeNull()
        ->and($attr->is_variant)->toBeTrue()
        ->and($attr->type)->toBe(AttributeType::Select);

    // Add options to the color attribute
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.vertical-attributes', ['vertical' => $vertical])
        ->call('openOptionsModal', $attr->id)
        ->set('new_option_label', 'Navy Blue')
        ->set('new_option_value', 'navy-blue')
        ->call('addOption')
        ->assertHasNoErrors();

    $attr->refresh();
    expect($attr->options)->toHaveCount(1)
        ->and($attr->options[0]['label'])->toBe('Navy Blue')
        ->and($attr->options[0]['value'])->toBe('navy-blue')
        ->and($attr->options[0]['is_active'])->toBeTrue();
});

test('admin can toggle option active status', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $cat = Category::create(['name' => 'Topwear']);
    $vertical = Vertical::create([
        'category_id' => $cat->id,
        'name' => 'T-Shirt',
        'hsn_code' => '6109',
        'gst_rate' => 5.00,
    ]);

    $attr = VerticalAttribute::create([
        'vertical_id' => $vertical->id,
        'name' => 'Size',
        'code' => 'size',
        'type' => AttributeType::Select,
        'options' => [
            ['label' => 'S', 'value' => 's', 'is_active' => true],
            ['label' => 'M', 'value' => 'm', 'is_active' => true],
        ],
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.vertical-attributes', ['vertical' => $vertical])
        ->call('openOptionsModal', $attr->id)
        ->call('toggleOptionActive', 0);

    $attr->refresh();
    expect($attr->options[0]['is_active'])->toBeFalse()
        ->and($attr->options[1]['is_active'])->toBeTrue();
});

test('attribute code, type and is_variant are locked when used by catalog products', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $cat = Category::create(['name' => 'Topwear']);
    $vertical = Vertical::create([
        'category_id' => $cat->id,
        'name' => 'T-Shirt',
        'hsn_code' => '6109',
        'gst_rate' => 5.00,
    ]);

    $brand = Brand::create(['name' => 'Brand Test']);

    $attr = VerticalAttribute::create([
        'vertical_id' => $vertical->id,
        'name' => 'Fabric',
        'code' => 'fabric',
        'type' => AttributeType::Select,
        'is_variant' => false,
        'options' => [
            ['label' => 'Cotton', 'value' => 'cotton', 'is_active' => true],
        ],
    ]);

    // Create a product using this attribute
    Product::create([
        'vertical_id' => $vertical->id,
        'brand_id' => $brand->id,
        'title' => 'Plain T-Shirt',
        'description' => 'Test desc',
        'specs' => ['fabric' => 'cotton'],
    ]);

    expect($attr->isUsedInProducts())->toBeTrue();

    // Try modifying code or type
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.vertical-attributes', ['vertical' => $vertical])
        ->call('openEditModal', $attr->id)
        ->set('code', 'fabric_changed')
        ->call('saveAttribute')
        ->assertHasErrors(['code']);

    // Attribute code remains unchanged
    expect($attr->fresh()->code)->toBe('fabric');

    // Deleting the attribute is also blocked
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.vertical-attributes', ['vertical' => $vertical])
        ->call('deleteAttribute', $attr->id);

    expect(VerticalAttribute::find($attr->id))->not->toBeNull();
});

test('used options cannot be deleted from vertical attribute', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $cat = Category::create(['name' => 'Topwear']);
    $vertical = Vertical::create([
        'category_id' => $cat->id,
        'name' => 'T-Shirt',
        'hsn_code' => '6109',
        'gst_rate' => 5.00,
    ]);

    $brand = Brand::create(['name' => 'Brand Test']);

    $attr = VerticalAttribute::create([
        'vertical_id' => $vertical->id,
        'name' => 'Size',
        'code' => 'size',
        'type' => AttributeType::Select,
        'is_variant' => true,
        'options' => [
            ['label' => 'S', 'value' => 's', 'is_active' => true],
            ['label' => 'Unused Size', 'value' => 'unused', 'is_active' => true],
        ],
    ]);

    $product = Product::create([
        'vertical_id' => $vertical->id,
        'brand_id' => $brand->id,
        'title' => 'Plain T-Shirt',
        'description' => 'Test desc',
    ]);

    ProductVariant::create([
        'product_id' => $product->id,
        'variant_attributes' => ['size' => 's'],
        'variant_key' => 'size:s',
    ]);

    expect($attr->isOptionUsed('s'))->toBeTrue()
        ->and($attr->isOptionUsed('unused'))->toBeFalse();

    // Try removing the used option (index 0)
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.vertical-attributes', ['vertical' => $vertical])
        ->call('openOptionsModal', $attr->id)
        ->call('removeOption', 0);

    // Option 's' was NOT removed
    $attr->refresh();
    expect($attr->options)->toHaveCount(2);

    // Unused option (index 1) CAN be removed
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.vertical-attributes', ['vertical' => $vertical])
        ->call('openOptionsModal', $attr->id)
        ->call('removeOption', 1);

    $attr->refresh();
    expect($attr->options)->toHaveCount(1)
        ->and($attr->options[0]['value'])->toBe('s');
});
