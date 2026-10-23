<?php

use App\Enums\BrandStatus;
use App\Enums\ProductQcStatus;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use Livewire\Livewire;

test('admin can view brand directory and search', function () {
    $admin = Admin::factory()->superAdmin()->create();
    Brand::create(['name' => 'Nike', 'status' => BrandStatus::Approved]);
    Brand::create(['name' => 'Adidas', 'status' => BrandStatus::Approved]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.brands')
        ->assertSee('Nike')
        ->assertSee('Adidas')
        ->set('search', 'Nik')
        ->assertSee('Nike')
        ->assertDontSee('Adidas');
});

test('admin can create and auto-approve a brand', function () {
    $admin = Admin::factory()->superAdmin()->create();

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.brands')
        ->call('openCreateModal')
        ->set('name', 'Puma')
        ->call('createBrand')
        ->assertHasNoErrors();

    $brand = Brand::where('name', 'Puma')->first();
    expect($brand)->not->toBeNull()
        ->and($brand->status)->toBe(BrandStatus::Approved)
        ->and($brand->slug)->toBe('puma');
});

test('admin can approve a seller-requested brand', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $user->id]);

    $brand = Brand::create([
        'name' => 'Handmade Crafts',
        'status' => BrandStatus::Pending,
        'requested_by' => $seller->id,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.brands')
        ->set('status_filter', 'pending')
        ->assertSee('Handmade Crafts')
        ->call('approveBrand', $brand->id);

    expect($brand->fresh()->status)->toBe(BrandStatus::Approved)
        ->and($brand->fresh()->rejection_reason)->toBeNull();
});

test('admin can reject a seller-requested brand and associated products revert to rejected', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $user->id]);

    $brand = Brand::create([
        'name' => 'Knockoff Logo',
        'status' => BrandStatus::Pending,
        'requested_by' => $seller->id,
    ]);

    $cat = Category::create(['name' => 'Apparel']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Tees', 'hsn_code' => '6109', 'gst_rate' => 5.0]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Sample Shirt',
        'description' => 'Test',
        'qc_status' => ProductQcStatus::QcPending,
        'created_by' => $seller->id,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.brands')
        ->call('openRejectModal', $brand->id)
        ->set('rejection_reason', 'Trademark violation detected.')
        ->call('confirmReject')
        ->assertHasNoErrors();

    expect($brand->fresh()->status)->toBe(BrandStatus::Rejected)
        ->and($brand->fresh()->rejection_reason)->toBe('Trademark violation detected.');

    // The product in pending QC is reverted to rejected with notes
    expect($product->fresh()->qc_status)->toBe(ProductQcStatus::Rejected)
        ->and($product->fresh()->qc_notes)->toContain('brand');
});
