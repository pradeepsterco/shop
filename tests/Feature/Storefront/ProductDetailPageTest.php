<?php

use App\Enums\AttributeType;
use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Models\VerticalAttribute;
use App\Services\CartService;
use App\Services\UpidGenerator;
use Livewire\Livewire;

function createProductDetailContext(): array
{
    $brand = Brand::create(['name' => 'ToyMasters', 'slug' => 'toymasters-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Action Figures', 'slug' => 'action-figures-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Figures', 'slug' => 'figures-'.uniqid(), 'hsn_code' => '9503', 'gst_rate' => 12]);

    $colorAttr = VerticalAttribute::create([
        'vertical_id' => $vert->id,
        'name' => 'Color',
        'code' => 'color',
        'type' => AttributeType::Select,
        'is_variant' => true,
        'options' => [
            ['value' => 'black', 'label' => 'Black', 'is_active' => true],
            ['value' => 'red', 'label' => 'Red', 'is_active' => true],
        ],
    ]);

    $sizeAttr = VerticalAttribute::create([
        'vertical_id' => $vert->id,
        'name' => 'Size',
        'code' => 'size',
        'type' => AttributeType::Select,
        'is_variant' => true,
        'options' => [
            ['value' => 's', 'label' => 'S', 'is_active' => true],
            ['value' => 'm', 'label' => 'M', 'is_active' => true],
        ],
    ]);

    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Toy Store Deluxe',
    ]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Mecha Titan Action Figure',
        'slug' => 'mecha-titan-'.uniqid(),
        'description' => 'Detailed mecha action figure with accessories.',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    ProductImage::create([
        'product_id' => $product->id,
        'path' => 'products/mecha.jpg',
        'is_primary' => true,
        'status' => ProductImageStatus::Live,
        'sort_order' => 1,
    ]);

    // Variant 1: Black, S
    $variant1 = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'black', 'size' => 's'],
        'attribute_snapshot' => [
            'color' => ['value' => 'black', 'label' => 'Black'],
            'size' => ['value' => 's', 'label' => 'S'],
        ],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    // Variant 2: Black, M
    $variant2 = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'black', 'size' => 'm'],
        'attribute_snapshot' => [
            'color' => ['value' => 'black', 'label' => 'Black'],
            'size' => ['value' => 'm', 'label' => 'M'],
        ],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    // Variant 3: Red, S (Notice: No Red, M exists!)
    $variant3 = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'red', 'size' => 's'],
        'attribute_snapshot' => [
            'color' => ['value' => 'red', 'label' => 'Red'],
            'size' => ['value' => 's', 'label' => 'S'],
        ],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    return compact('brand', 'cat', 'vert', 'user', 'seller', 'product', 'variant1', 'variant2', 'variant3');
}

test('product detail renders and multi-attribute selector disables unavailable combinations', function () {
    $ctx = createProductDetailContext();

    // Create listing for Variant 3 (Red, S)
    Listing::create([
        'seller_profile_id' => $ctx['seller']->id,
        'product_variant_id' => $ctx['variant3']->id,
        'sku' => 'SKU-RED-S',
        'mrp' => 150000,
        'selling_price' => 120000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    // View Variant 3 (Red, S)
    $component = Livewire::test('pages::storefront.product-show', [
        'slug' => $ctx['product']->slug,
        'upid' => $ctx['variant3']->upid,
    ]);

    $component->assertOk()
        ->assertSee('Mecha Titan Action Figure')
        ->assertSee('Toy Store Deluxe')
        ->assertSee('₹1,200');

    // On Red, S: "M" size combination does NOT exist for Red, so it must be disabled!
    $matrix = $component->get('attributeMatrix');
    expect($matrix['size']['options'])->toContain([
        'value' => 'm',
        'label' => 'm',
        'is_selected' => false,
        'is_available' => false, // unavailable combo!
        'target_upid' => null,
    ]);
});

test('add to cart adds listing, dispatches cart-updated, and toggles button to GO TO CART', function () {
    $ctx = createProductDetailContext();
    $listing = Listing::create([
        'seller_profile_id' => $ctx['seller']->id,
        'product_variant_id' => $ctx['variant1']->id,
        'sku' => 'SKU-BLACK-S',
        'mrp' => 150000,
        'selling_price' => 119900,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    $component = Livewire::test('pages::storefront.product-show', [
        'slug' => $ctx['product']->slug,
        'upid' => $ctx['variant1']->upid,
    ]);

    $component->assertSee('ADD TO CART')
        ->assertDontSee('GO TO CART')
        ->call('addToCart')
        ->assertDispatched('cart-updated')
        ->assertSee('GO TO CART');

    expect(app(CartService::class)->hasListing($listing->id))->toBeTrue();
});

test('buy now for guest saves pending session and redirects to login', function () {
    $ctx = createProductDetailContext();
    $listing = Listing::create([
        'seller_profile_id' => $ctx['seller']->id,
        'product_variant_id' => $ctx['variant1']->id,
        'sku' => 'SKU-BLACK-S',
        'mrp' => 150000,
        'selling_price' => 119900,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    Livewire::test('pages::storefront.product-show', [
        'slug' => $ctx['product']->slug,
        'upid' => $ctx['variant1']->upid,
    ])
        ->call('buyNow')
        ->assertRedirect(route('login'));

    expect(session('pending_buy_now_listing_id'))->toBe($listing->id);
    expect(session('url.intended'))->toBe(route('checkout.resume-buy-now'));
});

test('seller viewing their own listing cannot add to cart or buy now', function () {
    $ctx = createProductDetailContext();
    Listing::create([
        'seller_profile_id' => $ctx['seller']->id,
        'product_variant_id' => $ctx['variant1']->id,
        'sku' => 'SKU-BLACK-S',
        'mrp' => 150000,
        'selling_price' => 119900,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    // Seller is logged in
    $this->actingAs($ctx['user']);

    Livewire::test('pages::storefront.product-show', [
        'slug' => $ctx['product']->slug,
        'upid' => $ctx['variant1']->upid,
    ])
        ->assertSee('You are the seller of this listing')
        ->assertDontSee('ADD TO CART')
        ->assertDontSee('BUY NOW');
});

test('out of stock variant displays Out of stock and disables purchase buttons', function () {
    $ctx = createProductDetailContext();
    Listing::create([
        'seller_profile_id' => $ctx['seller']->id,
        'product_variant_id' => $ctx['variant1']->id,
        'sku' => 'SKU-BLACK-S',
        'mrp' => 150000,
        'selling_price' => 119900,
        'stock' => 0, // out of stock
        'status' => ListingStatus::Active,
    ]);

    Livewire::test('pages::storefront.product-show', [
        'slug' => $ctx['product']->slug,
        'upid' => $ctx['variant1']->upid,
    ])
        ->assertSee('Out of stock')
        ->assertDontSee('ADD TO CART')
        ->assertDontSee('BUY NOW');
});
