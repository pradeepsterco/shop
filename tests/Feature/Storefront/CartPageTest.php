<?php

use App\Checkout\Models\CheckoutIntent;
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
use App\Services\CartService;
use App\Services\UpidGenerator;
use Livewire\Livewire;

function createCartTestContext(): array
{
    $brand = Brand::create(['name' => 'LegoToy', 'slug' => 'legotoy-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Building Sets', 'slug' => 'building-sets-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Sets', 'slug' => 'sets-'.uniqid(), 'hsn_code' => '9503', 'gst_rate' => 12]);

    $sellerUser1 = User::factory()->create();
    $seller1 = SellerProfile::factory()->create([
        'user_id' => $sellerUser1->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Seller Prime',
    ]);

    $sellerUser2 = User::factory()->create();
    $seller2 = SellerProfile::factory()->create([
        'user_id' => $sellerUser2->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Seller Super',
    ]);

    $product1 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Space Station Kit',
        'slug' => 'space-station-kit-'.uniqid(),
        'description' => 'Space building kit',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $variant1 = ProductVariant::create([
        'product_id' => $product1->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'blue'],
        'attribute_snapshot' => ['color' => ['value' => 'blue', 'label' => 'Blue']],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $product2 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Lunar Rover Model',
        'slug' => 'lunar-rover-'.uniqid(),
        'description' => 'Lunar rover model kit',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $variant2 = ProductVariant::create([
        'product_id' => $product2->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'white'],
        'attribute_snapshot' => ['color' => ['value' => 'white', 'label' => 'White']],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    return compact('brand', 'cat', 'vert', 'seller1', 'seller2', 'product1', 'variant1', 'product2', 'variant2');
}

test('cart page renders empty state when cart has no items', function () {
    Livewire::test('pages::storefront.cart')
        ->assertSee('Your cart is empty!')
        ->assertSee('Shop now');
});

test('cart page renders items grouped by seller profile with price details', function () {
    $ctx = createCartTestContext();

    $listing1 = Listing::create([
        'seller_profile_id' => $ctx['seller1']->id,
        'product_variant_id' => $ctx['variant1']->id,
        'sku' => 'SKU-SPACE',
        'mrp' => 150000,
        'selling_price' => 119900,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    $listing2 = Listing::create([
        'seller_profile_id' => $ctx['seller2']->id,
        'product_variant_id' => $ctx['variant2']->id,
        'sku' => 'SKU-LUNAR',
        'mrp' => 200000,
        'selling_price' => 180000,
        'stock' => 5,
        'status' => ListingStatus::Active,
    ]);

    $cartService = app(CartService::class);
    $cartService->add($listing1->id, 1);
    $cartService->add($listing2->id, 2);

    $component = Livewire::test('pages::storefront.cart');

    $component->assertOk()
        ->assertSee('Seller Prime')
        ->assertSee('Seller Super')
        ->assertSee('Space Station Kit')
        ->assertSee('Lunar Rover Model')
        ->assertSee('₹1,199')
        ->assertSee('₹1,800')
        ->assertSee('Place Order');
});

test('cart page stepper increases and decreases qty and updates totals', function () {
    $ctx = createCartTestContext();
    $listing = Listing::create([
        'seller_profile_id' => $ctx['seller1']->id,
        'product_variant_id' => $ctx['variant1']->id,
        'sku' => 'SKU-SPACE-1',
        'mrp' => 100000,
        'selling_price' => 80000,
        'stock' => 5,
        'status' => ListingStatus::Active,
    ]);

    $cartService = app(CartService::class);
    $cartService->add($listing->id, 1);
    $view = $cartService->view();
    $itemId = $view['sellers'][0]['items'][0]['id'];

    $component = Livewire::test('pages::storefront.cart');

    // Increase
    $component->call('increaseQty', $itemId)
        ->assertDispatched('cart-updated');

    expect($cartService->view()['units'])->toBe(2);

    // Decrease
    $component->call('decreaseQty', $itemId)
        ->assertDispatched('cart-updated');

    expect($cartService->view()['units'])->toBe(1);

    // Remove
    $component->call('removeItem', $itemId)
        ->assertDispatched('cart-updated')
        ->assertSee('Your cart is empty!');

    expect($cartService->view()['lines'])->toBe(0);
});

test('guest clicking place order redirects to login with intended url', function () {
    $ctx = createCartTestContext();
    $listing = Listing::create([
        'seller_profile_id' => $ctx['seller1']->id,
        'product_variant_id' => $ctx['variant1']->id,
        'sku' => 'SKU-SPACE-2',
        'mrp' => 100000,
        'selling_price' => 80000,
        'stock' => 5,
        'status' => ListingStatus::Active,
    ]);

    $cartService = app(CartService::class);
    $cartService->add($listing->id, 1);

    Livewire::test('pages::storefront.cart')
        ->call('placeOrder')
        ->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe(route('cart'));
});

test('authenticated customer clicking place order creates checkout intent and opens checkout summary', function () {
    $ctx = createCartTestContext();
    $listing = Listing::create([
        'seller_profile_id' => $ctx['seller1']->id,
        'product_variant_id' => $ctx['variant1']->id,
        'sku' => 'SKU-SPACE-3',
        'mrp' => 150000,
        'selling_price' => 119900,
        'stock' => 5,
        'status' => ListingStatus::Active,
    ]);

    $customer = User::factory()->create();
    $this->actingAs($customer);

    $cartService = app(CartService::class);
    $cartService->add($listing->id, 1);

    $component = Livewire::test('pages::storefront.cart')
        ->call('placeOrder');

    $intent = CheckoutIntent::where('user_id', $customer->id)->first();
    expect($intent)->not->toBeNull();
    expect($intent->source)->toBe('cart');

    $component->assertRedirect(route('checkout.show', ['intent' => $intent->id]));
});
