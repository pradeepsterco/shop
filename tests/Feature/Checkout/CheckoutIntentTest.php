<?php

use App\Checkout\Models\CheckoutIntent;
use App\Checkout\Services\CheckoutIntentService;
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
use App\Services\CartService;
use App\Services\UpidGenerator;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

function createTestCatalog(): array
{
    $brand = Brand::create(['name' => 'ToyCraft', 'slug' => 'toycraft-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Toys', 'slug' => 'toys-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Dolls', 'slug' => 'dolls-'.uniqid(), 'hsn_code' => '9503', 'gst_rate' => 12]);

    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Toy Store',
    ]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Robot Toy',
        'slug' => 'robot-toy-'.uniqid(),
        'description' => 'Great toy',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $image = ProductImage::create([
        'product_id' => $product->id,
        'path' => 'products/robot.jpg',
        'is_primary' => true,
        'status' => ProductImageStatus::Live,
        'sort_order' => 1,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'black'],
        'attribute_snapshot' => ['color' => ['value' => 'black', 'label' => 'Black']],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    return compact('brand', 'cat', 'vert', 'user', 'seller', 'product', 'image', 'variant');
}

function createListing(array $ctx, array $overrides = []): Listing
{
    return Listing::create(array_merge([
        'seller_profile_id' => $ctx['seller']->id,
        'product_variant_id' => $ctx['variant']->id,
        'sku' => 'SKU-'.uniqid(),
        'mrp' => 150000,          // 150000 paise = ₹1,500
        'selling_price' => 119900, // 119900 paise = ₹1,199
        'stock' => 10,
        'status' => ListingStatus::Active,
    ], $overrides));
}

test('₹1,199 listing shows ₹1,199 in cart and checkout summary without multiplying by 100', function () {
    $ctx = createTestCatalog();
    $listing = createListing($ctx, [
        'mrp' => 150000,
        'selling_price' => 119900,
        'stock' => 5,
    ]);

    $customer = User::factory()->create();

    // 1. In Cart
    $cartService = app(CartService::class);
    $this->actingAs($customer);
    $cartService->add($listing->id, 1);
    $cartView = $cartService->view();

    expect((int) ($cartView['subtotal'] / 100))->toBe(1199);
    expect((int) ($cartView['mrp_total'] / 100))->toBe(1500);

    // 2. In Checkout Summary
    $checkoutService = app(CheckoutIntentService::class);
    $intent = $checkoutService->createFromCart($customer, $cartView['sellers'][0]['items']);
    $eval = $checkoutService->evaluateIntent($intent);

    expect((int) ($eval['summary']['selling_total'] / 100))->toBe(1199);
    expect((int) ($eval['summary']['mrp_total'] / 100))->toBe(1500);
    expect((int) ($eval['summary']['payable_total'] / 100))->toBe(1199);
});

test('buy now intent creation is idempotent and reuses pending intent', function () {
    $ctx = createTestCatalog();
    $listing = createListing($ctx);
    $customer = User::factory()->create();

    $checkoutService = app(CheckoutIntentService::class);

    $intent1 = $checkoutService->createOrReuseBuyNowIntent($customer, $listing->id);
    $intent2 = $checkoutService->createOrReuseBuyNowIntent($customer, $listing->id);

    expect($intent1->id)->toBe($intent2->id);
    expect(CheckoutIntent::where('user_id', $customer->id)->count())->toBe(1);
});

test('seller cannot purchase their own listing via buy now or cart', function () {
    $ctx = createTestCatalog();
    $listing = createListing($ctx);

    // ctx['user'] is the seller
    $sellerUser = $ctx['user'];
    $checkoutService = app(CheckoutIntentService::class);

    // 1. Buy Now
    expect(fn () => $checkoutService->createOrReuseBuyNowIntent($sellerUser, $listing->id))
        ->toThrow(DomainException::class, 'You cannot purchase your own listings.');

    // 2. From Cart Lines
    $lines = [[
        'listing_id' => $listing->id,
        'seller_profile_id' => $ctx['seller']->id,
        'qty' => 1,
        'unit_price' => 119900,
        'mrp' => 150000,
    ]];

    expect(fn () => $checkoutService->createFromCart($sellerUser, $lines))
        ->toThrow(DomainException::class, 'You cannot purchase your own listings.');
});

test('plain GET to resume buy now without session key does not create an intent', function () {
    $customer = User::factory()->create();

    $this->actingAs($customer)
        ->get(route('checkout.resume-buy-now'))
        ->assertRedirect(route('cart'));

    expect(CheckoutIntent::count())->toBe(0);
});

test('guest register returns to intended url and resumes buy now', function () {
    $ctx = createTestCatalog();
    $listing = createListing($ctx);

    // Guest sets intended and pending listing in session
    $response = $this->withSession([
        'pending_buy_now_listing_id' => $listing->id,
        'url.intended' => route('checkout.resume-buy-now'),
    ])->post(route('register'), [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('checkout.resume-buy-now'));

    $user = User::where('email', 'john@example.com')->first();
    expect($user)->not->toBeNull();

    // Acting as the newly registered user, visiting resume route processes the session listing
    $resumeResponse = $this->actingAs($user)
        ->withSession(['pending_buy_now_listing_id' => $listing->id])
        ->get(route('checkout.resume-buy-now'));

    $intent = CheckoutIntent::where('user_id', $user->id)->first();
    expect($intent)->not->toBeNull();
    expect($intent->source)->toBe('buy_now');
    $resumeResponse->assertRedirect(route('checkout.show', ['intent' => $intent->id]));
});

test('buy-now resume uses session pull and running twice redirects to cart without creating another intent', function () {
    $ctx = createTestCatalog();
    $listing = createListing($ctx);
    $customer = User::factory()->create();

    // First hit: session has pending listing id
    $firstResponse = $this->actingAs($customer)
        ->withSession(['pending_buy_now_listing_id' => $listing->id])
        ->get(route('checkout.resume-buy-now'));

    $intent = CheckoutIntent::where('user_id', $customer->id)->first();
    expect($intent)->not->toBeNull();
    $firstResponse->assertRedirect(route('checkout.show', ['intent' => $intent->id]));
    expect(CheckoutIntent::where('user_id', $customer->id)->count())->toBe(1);

    // Second hit: session key has already been pulled so it redirects to cart without creating or touching intents
    $secondResponse = $this->actingAs($customer)
        ->get(route('checkout.resume-buy-now'));

    $secondResponse->assertRedirect(route('cart'));
    expect(CheckoutIntent::where('user_id', $customer->id)->count())->toBe(1);
});

test('header cart badge single-file component renders count and updates on cart-updated event', function () {
    $ctx = createTestCatalog();
    $listing = createListing($ctx);

    $component = Livewire::test('header-cart-badge');
    $component->assertDontSee('bg-amber-500');

    // Add item to cart
    app(CartService::class)->add($listing->id, 2);

    $component->dispatch('cart-updated')
        ->assertSee('2');
});

test('checkout summary page redirects when intent is expired', function () {
    $ctx = createTestCatalog();
    $listing = createListing($ctx);
    $customer = User::factory()->create();

    $intent = CheckoutIntent::create([
        'user_id' => $customer->id,
        'source' => 'cart',
        'lines' => [[
            'listing_id' => $listing->id,
            'qty' => 1,
            'unit_price' => 119900,
            'mrp' => 150000,
        ]],
        'status' => 'pending',
        'expires_at' => now()->subMinutes(10), // expired
    ]);

    $this->actingAs($customer);

    Livewire::test('pages::storefront.checkout-summary', ['intent' => $intent->id])
        ->assertRedirect(route('cart'));
});

test('checkout summary partitions unavailable items and excludes them from total', function () {
    $ctx = createTestCatalog();
    $variant2 = ProductVariant::create([
        'product_id' => $ctx['product']->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'red'],
        'attribute_snapshot' => ['color' => ['value' => 'red', 'label' => 'Red']],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $listing1 = createListing($ctx, ['selling_price' => 10000, 'mrp' => 12000, 'stock' => 5]);
    $listing2 = createListing($ctx, ['product_variant_id' => $variant2->id, 'selling_price' => 20000, 'mrp' => 25000, 'stock' => 0]); // out of stock

    $customer = User::factory()->create();
    $checkoutService = app(CheckoutIntentService::class);

    $intent = CheckoutIntent::create([
        'user_id' => $customer->id,
        'source' => 'cart',
        'lines' => [
            [
                'listing_id' => $listing1->id,
                'name' => 'Item 1',
                'qty' => 1,
                'unit_price' => 10000,
                'mrp' => 12000,
            ],
            [
                'listing_id' => $listing2->id,
                'name' => 'Item 2',
                'qty' => 1,
                'unit_price' => 20000,
                'mrp' => 25000,
            ],
        ],
        'status' => 'pending',
        'expires_at' => now()->addMinutes(30),
    ]);

    $eval = $checkoutService->evaluateIntent($intent);

    expect(count($eval['active_lines']))->toBe(1);
    expect(count($eval['unavailable_lines']))->toBe(1);
    expect($eval['summary']['payable_total'])->toBe(10000); // Only item 1 is included
});

test('scheduled command checkout:prune-intents prunes intents older than specified days', function () {
    $customer = User::factory()->create();

    // Old intent (8 days old)
    $old = CheckoutIntent::create([
        'user_id' => $customer->id,
        'source' => 'cart',
        'lines' => [],
        'status' => 'pending',
        'expires_at' => now()->subDays(8),
    ]);
    $old->created_at = now()->subDays(8);
    $old->save();

    // Fresh intent
    $fresh = CheckoutIntent::create([
        'user_id' => $customer->id,
        'source' => 'cart',
        'lines' => [],
        'status' => 'pending',
        'expires_at' => now()->addMinutes(30),
    ]);

    Artisan::call('checkout:prune-intents', ['--days' => 7]);

    expect(CheckoutIntent::find($old->id))->toBeNull();
    expect(CheckoutIntent::find($fresh->id))->not->toBeNull();
});

test('user cannot view or mutate another users checkout intent', function () {
    $ctx = createTestCatalog();
    $listing = createListing($ctx);

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $intent = CheckoutIntent::create([
        'user_id' => $userA->id,
        'source' => 'cart',
        'lines' => [[
            'listing_id' => $listing->id,
            'qty' => 1,
            'unit_price' => 119900,
            'mrp' => 150000,
        ]],
        'status' => 'pending',
        'expires_at' => now()->addMinutes(30),
    ]);

    $this->actingAs($userB);

    Livewire::test('pages::storefront.checkout-summary', ['intent' => $intent->id])
        ->assertForbidden();
});

test('checkout summary allows updating qty within stock limits and removing lines', function () {
    $ctx = createTestCatalog();
    $listing = createListing($ctx, ['stock' => 5]);
    $customer = User::factory()->create();

    $intent = CheckoutIntent::create([
        'user_id' => $customer->id,
        'source' => 'cart',
        'lines' => [[
            'listing_id' => $listing->id,
            'name' => 'Robot',
            'qty' => 2,
            'unit_price' => 119900,
            'mrp' => 150000,
        ]],
        'status' => 'pending',
        'expires_at' => now()->addMinutes(30),
    ]);

    $this->actingAs($customer);

    Livewire::test('pages::storefront.checkout-summary', ['intent' => $intent->id])
        ->call('increaseQty', $listing->id)
        ->assertSee('3')
        ->call('decreaseQty', $listing->id)
        ->assertSee('2')
        ->call('removeLine', $listing->id)
        ->assertRedirect(route('cart'));

    expect($intent->fresh()->lines)->toBe([]);
});
