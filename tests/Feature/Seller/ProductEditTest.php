<?php

use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use Livewire\Livewire;

test('seller who is not creator cannot modify catalog specs', function () {
    $creatorUser = User::factory()->create();
    $creatorSeller = SellerProfile::factory()->create(['user_id' => $creatorUser->id, 'status' => SellerStatus::Approved]);

    $otherUser = User::factory()->create();
    $otherSeller = SellerProfile::factory()->create(['user_id' => $otherUser->id, 'status' => SellerStatus::Approved]);

    $brand = Brand::create(['name' => 'Nike', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Apparel']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Tees', 'hsn_code' => '6109', 'gst_rate' => 5.0]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Original Nike Tee',
        'description' => 'Original description',
        'created_by' => $creatorSeller->id,
        'qc_status' => ProductQcStatus::Approved,
        'specs' => ['fabric' => 'cotton'],
    ]);

    // Other seller attempts to call saveCatalogContent
    Livewire::actingAs($otherUser)
        ->test('pages::seller.products-edit', ['product' => $product])
        ->assertSet('isCreator', false)
        ->call('saveCatalogContent')
        ->assertForbidden();

    expect($product->fresh()->title)->toBe('Original Nike Tee');
});

test('creator editing live approved product content places updates in pending_changes', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $user->id, 'status' => SellerStatus::Approved]);

    $brand = Brand::create(['name' => 'Nike', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Apparel']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Tees', 'hsn_code' => '6109', 'gst_rate' => 5.0]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Live Product Title',
        'description' => 'Live description that customers currently see',
        'created_by' => $seller->id,
        'qc_status' => ProductQcStatus::Approved,
        'specs' => ['fabric' => 'cotton'],
    ]);

    Livewire::actingAs($user)
        ->test('pages::seller.products-edit', ['product' => $product])
        ->assertSet('isCreator', true)
        ->set('title', 'Proposed New Title Awaiting Review')
        ->set('description', 'Updated new description with more features')
        ->set('specs', ['fabric' => 'organic-cotton'])
        ->set('generic_name', 'T-shirt')
        ->set('net_quantity', '1')
        ->call('saveCatalogContent')
        ->assertHasNoErrors();

    $product->refresh();

    // Crucial rule: Live columns remain UNCHANGED
    expect($product->title)->toBe('Live Product Title')
        ->and($product->description)->toBe('Live description that customers currently see')
        ->and($product->specs)->toBe(['fabric' => 'cotton'])
        ->and($product->generic_name)->toBeNull()
        // But pending_changes holds the new draft waiting in QC
        ->and($product->qc_status)->toBe(ProductQcStatus::QcPending)
        ->and($product->pending_changes['title'])->toBe('Proposed New Title Awaiting Review')
        ->and($product->pending_changes['specs'])->toBe(['fabric' => 'organic-cotton'])
        ->and($product->pending_changes['generic_name'])->toBe('T-shirt')
        ->and($product->pending_changes['net_quantity_unit'])->toBe('count');
});

test('creator cannot save catalog content without generic name and net quantity', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $user->id, 'status' => SellerStatus::Approved]);
    $product = Product::factory()->create(['created_by' => $seller->id, 'generic_name' => null, 'net_quantity' => null]);

    Livewire::actingAs($user)
        ->test('pages::seller.products-edit', ['product' => $product])
        ->set('net_quantity', '0')
        ->set('net_quantity_unit', 'bushel')
        ->call('saveCatalogContent')
        ->assertHasErrors(['generic_name' => 'required', 'net_quantity' => 'gt', 'net_quantity_unit']);

    expect($product->fresh()->pending_changes)->toBeNull();
});

test('edit form shows declarations waiting in QC', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $user->id, 'status' => SellerStatus::Approved]);
    $product = Product::factory()->qcPending()->create([
        'created_by' => $seller->id,
        'generic_name' => null,
        'net_quantity' => null,
        'net_quantity_unit' => null,
        'pending_changes' => ['generic_name' => 'Board game', 'net_quantity' => '2', 'net_quantity_unit' => 'count'],
    ]);

    Livewire::actingAs($user)
        ->test('pages::seller.products-edit', ['product' => $product])
        ->assertSet('generic_name', 'Board game')
        ->assertSet('net_quantity', '2')
        ->assertSet('net_quantity_unit', 'count');
});

test('seller can update price and stock on existing listings with instant save', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $user->id, 'status' => SellerStatus::Approved]);

    $brand = Brand::create(['name' => 'Nike', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Apparel']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Tees', 'hsn_code' => '6109', 'gst_rate' => 5.0]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Nike Tee',
        'description' => 'Description',
        'created_by' => $seller->id,
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => 'KHP1234567890AB',
        'variant_key' => 'size:m',
        'variant_attributes' => ['size' => 'm'],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $listing = Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'NIKE-TEE-M',
        'mrp' => 100000,
        'selling_price' => 80000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    Livewire::actingAs($user)
        ->test('pages::seller.products-edit', ['product' => $product])
        ->set("sellerListings.{$variant->id}.selling_price", '750.00')
        ->set("sellerListings.{$variant->id}.stock", '35')
        ->call('saveListingOffers')
        ->assertHasNoErrors();

    $listing->refresh();
    expect($listing->selling_price)->toBe(75000)
        ->and($listing->stock)->toBe(35);
});
