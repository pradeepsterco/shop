<?php

use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use Livewire\Livewire;

test('admin can view products list and filter by QC status', function () {
    $admin = Admin::factory()->superAdmin()->create();

    $cat = Category::create(['name' => 'Clothing']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'T-Shirt', 'hsn_code' => '6109', 'gst_rate' => 5]);
    $brand = Brand::create(['name' => 'UrbanStyle', 'status' => BrandStatus::Approved]);

    $pendingProd = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Pending Review T-Shirt',
        'description' => 'Description for pending shirt',
        'qc_status' => ProductQcStatus::QcPending,
    ]);

    $approvedProd = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Approved Live T-Shirt',
        'description' => 'Description for approved shirt',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.catalog.products'));
    $response->assertOk()
        ->assertSee('Pending Review T-Shirt');

    // Test Livewire component filtering
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.products')
        ->assertSet('status_filter', 'qc_pending')
        ->assertSee('Pending Review T-Shirt')
        ->assertDontSee('Approved Live T-Shirt')
        ->set('status_filter', 'approved')
        ->assertSee('Approved Live T-Shirt')
        ->assertDontSee('Pending Review T-Shirt');
});

test('admin can approve a product and its variants and activate seller listings', function () {
    $admin = Admin::factory()->superAdmin()->create();

    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
    ]);

    $cat = Category::create(['name' => 'Footwear']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Sneakers', 'hsn_code' => '6404', 'gst_rate' => 12]);
    $brand = Brand::create(['name' => 'SpeedRun', 'status' => BrandStatus::Approved]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'SpeedRun Pro Sneaker',
        'description' => 'Comfortable running shoes with breathable mesh.',
        'created_by' => $seller->id,
        'qc_status' => ProductQcStatus::QcPending,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => 'SPDRUN123456789',
        'variant_key' => 'size:9|color:blue',
        'variant_attributes' => ['size' => '9', 'color' => 'blue'],
        'qc_status' => VariantQcStatus::QcPending,
    ]);

    $listing = Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SKU-SPEED-9-BLU',
        'mrp' => 299900,
        'selling_price' => 199900,
        'stock' => 10,
        'status' => ListingStatus::Draft,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.products')
        ->call('approveProduct', $product->id)
        ->assertHasNoErrors();

    $freshProduct = $product->fresh();
    expect($freshProduct->qc_status)->toBe(ProductQcStatus::Approved)
        ->and($freshProduct->reviewed_by_admin_id)->toBe($admin->id)
        ->and($freshProduct->reviewed_at)->not->toBeNull();

    $freshVariant = $variant->fresh();
    expect($freshVariant->qc_status)->toBe(VariantQcStatus::Approved);

    $freshListing = $listing->fresh();
    expect($freshListing->status)->toBe(ListingStatus::Active);
});

test('admin can reject a product with reason', function () {
    $admin = Admin::factory()->superAdmin()->create();

    $cat = Category::create(['name' => 'Electronics']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Headphones', 'hsn_code' => '8518', 'gst_rate' => 18]);
    $brand = Brand::create(['name' => 'AudioMax', 'status' => BrandStatus::Approved]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Wireless Noise Cancelling Headphones',
        'description' => 'High fidelity wireless headphones.',
        'qc_status' => ProductQcStatus::QcPending,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => 'AUDMAX123456789',
        'variant_key' => 'color:black',
        'variant_attributes' => ['color' => 'black'],
        'qc_status' => VariantQcStatus::QcPending,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.products')
        ->call('openRejectModal', $product->id)
        ->set('rejection_reason', 'Product description violates marketplace quality guidelines.')
        ->call('confirmReject')
        ->assertHasNoErrors();

    $freshProduct = $product->fresh();
    expect($freshProduct->qc_status)->toBe(ProductQcStatus::Rejected)
        ->and($freshProduct->qc_notes)->toBe('Product description violates marketplace quality guidelines.')
        ->and($freshProduct->reviewed_by_admin_id)->toBe($admin->id);

    expect($variant->fresh()->qc_status)->toBe(VariantQcStatus::Rejected);
});
