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

function createSellerAndProduct(string $title = 'Test Product', int $stock = 25): array
{
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Store '.uniqid(),
    ]);

    $cat = Category::firstOrCreate(['name' => 'Fashion']);
    $vert = Vertical::firstOrCreate(
        ['name' => 'Shirts'],
        ['category_id' => $cat->id, 'hsn_code' => '6105', 'gst_rate' => 5]
    );
    $brand = Brand::firstOrCreate(
        ['name' => 'TopBrand'],
        ['status' => BrandStatus::Approved]
    );

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => $title,
        'description' => 'Description for '.$title,
        'created_by' => $seller->id,
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => 'UPID'.strtoupper(bin2hex(random_bytes(6))),
        'variant_key' => 'standard',
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $listing = Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SKU-'.strtoupper(bin2hex(random_bytes(4))),
        'mrp' => 100000,
        'selling_price' => 80000,
        'stock' => $stock,
        'status' => ListingStatus::Active,
    ]);

    return compact('user', 'seller', 'product', 'variant', 'listing');
}

test('owner can update stock using quick stock modal', function () {
    $data = createSellerAndProduct('Linen Casual Shirt', 30);

    Livewire::actingAs($data['user'])
        ->test('pages::seller.products-index')
        ->assertSee('Linen Casual Shirt')
        ->call('openStockModal', $data['product']->id)
        ->assertSet('showStockModal', true)
        ->assertSet('stockModalProductId', $data['product']->id)
        ->set("stockData.{$data['listing']->id}.current", 75)
        ->call('saveStock')
        ->assertSet('showStockModal', false)
        ->assertSee('Stock updated successfully');

    expect($data['listing']->fresh()->stock)->toBe(75);
});

test('another sellers product cannot be opened or saved', function () {
    $ownerData = createSellerAndProduct('Owner Shirt', 50);

    $otherUser = User::factory()->create();
    $otherSeller = SellerProfile::factory()->create([
        'user_id' => $otherUser->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Intruder Store',
    ]);

    // Other seller attempts to open owner's product
    Livewire::actingAs($otherUser)
        ->test('pages::seller.products-index')
        ->call('openStockModal', $ownerData['product']->id)
        ->assertSet('showStockModal', false);

    // Other seller directly attempts to save stock on owner's product
    Livewire::actingAs($otherUser)
        ->test('pages::seller.products-index')
        ->set('stockModalProductId', $ownerData['product']->id)
        ->set('openedListingIds', [$ownerData['listing']->id])
        ->set("stockData.{$ownerData['listing']->id}", [
            'original' => 50,
            'current' => 99,
        ])
        ->call('saveStock')
        ->assertForbidden();

    expect($ownerData['listing']->fresh()->stock)->toBe(50);
});

test('offer IDs outside the opened product are rejected', function () {
    $data1 = createSellerAndProduct('Product One', 20);

    // Create a second product with listing belonging to the same seller
    $variant2 = ProductVariant::create([
        'product_id' => $data1['product']->id,
        'upid' => 'UPID'.strtoupper(bin2hex(random_bytes(6))),
        'variant_key' => 'unrelated',
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $cat = Category::firstOrCreate(['name' => 'Fashion']);
    $vert = Vertical::firstOrCreate(
        ['name' => 'Pants'],
        ['category_id' => $cat->id, 'hsn_code' => '6105', 'gst_rate' => 5]
    );
    $product2 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $data1['product']->brand_id,
        'title' => 'Product Two',
        'description' => 'Unrelated product',
        'created_by' => $data1['seller']->id,
        'qc_status' => ProductQcStatus::Approved,
    ]);
    $variantOther = ProductVariant::create([
        'product_id' => $product2->id,
        'upid' => 'UPID'.strtoupper(bin2hex(random_bytes(6))),
        'variant_key' => 'standard',
        'qc_status' => VariantQcStatus::Approved,
    ]);
    $foreignListing = Listing::create([
        'seller_profile_id' => $data1['seller']->id,
        'product_variant_id' => $variantOther->id,
        'sku' => 'FOREIGN-SKU',
        'mrp' => 100000,
        'selling_price' => 80000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    // Open modal for Product 1, but inject foreign listing id into stockData
    Livewire::actingAs($data1['user'])
        ->test('pages::seller.products-index')
        ->call('openStockModal', $data1['product']->id)
        ->set("stockData.{$foreignListing->id}", [
            'original' => 10,
            'current' => 999,
        ])
        ->call('saveStock')
        ->assertForbidden();

    expect($foreignListing->fresh()->stock)->toBe(10);
});

test('negative or non-integer values are rejected', function () {
    $data = createSellerAndProduct('Validation Test Product', 40);

    // Negative stock
    Livewire::actingAs($data['user'])
        ->test('pages::seller.products-index')
        ->call('openStockModal', $data['product']->id)
        ->set("stockData.{$data['listing']->id}.current", -5)
        ->call('saveStock')
        ->assertHasErrors(["stockData.{$data['listing']->id}.current"])
        ->assertSet('showStockModal', true);

    expect($data['listing']->fresh()->stock)->toBe(40);

    // Non-integer string
    Livewire::actingAs($data['user'])
        ->test('pages::seller.products-index')
        ->call('openStockModal', $data['product']->id)
        ->set("stockData.{$data['listing']->id}.current", 'invalid-num')
        ->call('saveStock')
        ->assertHasErrors(["stockData.{$data['listing']->id}.current"]);

    expect($data['listing']->fresh()->stock)->toBe(40);

    // Exceeds max 999999
    Livewire::actingAs($data['user'])
        ->test('pages::seller.products-index')
        ->call('openStockModal', $data['product']->id)
        ->set("stockData.{$data['listing']->id}.current", 1000000)
        ->call('saveStock')
        ->assertHasErrors(["stockData.{$data['listing']->id}.current"]);

    expect($data['listing']->fresh()->stock)->toBe(40);
});

test('a stock change that happens between open and save is preserved via concurrency delta', function () {
    $data = createSellerAndProduct('Concurrent Sales Product', 100);

    $component = Livewire::actingAs($data['user'])
        ->test('pages::seller.products-index')
        ->call('openStockModal', $data['product']->id);

    // Seller saw 100 on open, wants to add 20 units (target 120, delta = +20)
    $component->set("stockData.{$data['listing']->id}.current", 120);

    // Meanwhile, an order comes in and decreases stock by 3 in DB:
    Listing::where('id', $data['listing']->id)->update(['stock' => 97]);

    // Seller submits their form:
    $component->call('saveStock')
        ->assertSet('showStockModal', false);

    // The result in DB must be: 97 + (120 - 100) = 117.
    // The 3 sales were preserved and not overwritten back to 120!
    expect($data['listing']->fresh()->stock)->toBe(117);
});

test('single variant and multi variant products both work with quick stock modal', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
    ]);

    $cat = Category::firstOrCreate(['name' => 'Fashion']);
    $vert = Vertical::firstOrCreate(
        ['name' => 'T-Shirts'],
        ['category_id' => $cat->id, 'hsn_code' => '6109', 'gst_rate' => 5]
    );
    $brand = Brand::firstOrCreate(
        ['name' => 'MultiBrand'],
        ['status' => BrandStatus::Approved]
    );

    // Multi-variant product: Size S and Size M
    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Crew Neck Tee',
        'description' => 'Multi-variant tee',
        'created_by' => $seller->id,
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $varS = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => 'UPID'.strtoupper(bin2hex(random_bytes(6))),
        'variant_key' => 'size:s',
        'variant_attributes' => ['size' => 's'],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $varM = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => 'UPID'.strtoupper(bin2hex(random_bytes(6))),
        'variant_key' => 'size:m',
        'variant_attributes' => ['size' => 'm'],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $listingS = Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $varS->id,
        'sku' => 'TEE-BLK-S',
        'mrp' => 50000,
        'selling_price' => 39900,
        'stock' => 15,
        'status' => ListingStatus::Active,
    ]);

    $listingM = Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $varM->id,
        'sku' => 'TEE-BLK-M',
        'mrp' => 50000,
        'selling_price' => 39900,
        'stock' => 25,
        'status' => ListingStatus::Active,
    ]);

    Livewire::actingAs($user)
        ->test('pages::seller.products-index')
        ->call('openStockModal', $product->id)
        ->assertSet('showStockModal', true)
        // Update Size S to 30
        ->set("stockData.{$listingS->id}.current", 30)
        // Set Size M to 0 using the helper
        ->call('setStockZero', $listingM->id)
        ->assertSet("stockData.{$listingM->id}.current", 0)
        ->call('saveStock')
        ->assertSet('showStockModal', false)
        ->assertSee('Stock updated successfully');

    expect($listingS->fresh()->stock)->toBe(30)
        ->and($listingM->fresh()->stock)->toBe(0);
});
