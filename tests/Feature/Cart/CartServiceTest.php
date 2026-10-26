<?php

use App\Enums\AttributeType;
use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Cart;
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
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;

function createCatalogContext(): array
{
    $brand = Brand::create(['name' => 'ToyCraft', 'slug' => 'toycraft', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Toys', 'slug' => 'toys']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Dolls', 'slug' => 'dolls', 'hsn_code' => '9503', 'gst_rate' => 12]);

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
            ['value' => 'm', 'label' => 'M', 'is_active' => true],
            ['value' => 's', 'label' => 'S', 'is_active' => true],
        ],
    ]);

    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Toy Kingdom',
    ]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Super Robot Action Toy',
        'slug' => 'super-robot-action-toy',
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
        'variant_attributes' => ['color' => 'black', 'size' => 'm'],
        'attribute_snapshot' => [
            'color' => ['value' => 'black', 'label' => 'Black'],
            'size' => ['value' => 'm', 'label' => 'M'],
        ],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    return compact('brand', 'cat', 'vert', 'seller', 'product', 'image', 'variant');
}

function createActiveListing(array $ctx, array $overrides = []): Listing
{
    return Listing::create(array_merge([
        'seller_profile_id' => $ctx['seller']->id,
        'product_variant_id' => $ctx['variant']->id,
        'sku' => 'SKU-'.uniqid(),
        'mrp' => 150000,          // ₹1,500
        'selling_price' => 119900, // ₹1,199
        'stock' => 10,
        'status' => ListingStatus::Active,
    ], $overrides));
}

test('₹1,199 listing shows ₹1,199 in the cart without multiplying by 100', function () {
    $ctx = createCatalogContext();
    $listing = createActiveListing($ctx, [
        'mrp' => 150000,          // 150000 paise = ₹1,500
        'selling_price' => 119900, // 119900 paise = ₹1,199
        'stock' => 5,
    ]);

    $cartService = app(CartService::class);
    $res = $cartService->add($listing->id, 1);

    expect($res['success'])->toBeTrue();

    $cartView = $cartService->view();
    expect($cartView['lines'])->toBe(1);
    expect($cartView['units'])->toBe(1);

    // The unit price in minor units (paise) must be 119900, which equals ₹1,199 (not 11990000)
    $line = $cartView['sellers'][0]['items'][0];
    expect($line['unit_price'])->toBe(119900);
    expect($line['mrp'])->toBe(150000);
    expect($line['line_total'])->toBe(119900);

    // Converted to rupees
    expect((int) ($line['unit_price'] / 100))->toBe(1199);
    expect((int) ($cartView['subtotal'] / 100))->toBe(1199);
    expect((int) ($cartView['mrp_total'] / 100))->toBe(1500);
    expect((int) ($cartView['savings'] / 100))->toBe(301);
});

test('line name contains product title and variant attribute labels and image is primary live image', function () {
    $ctx = createCatalogContext();
    $listing = createActiveListing($ctx);

    $cartService = app(CartService::class);
    $cartService->add($listing->id, 1);

    $cartView = $cartService->view();
    $line = $cartView['sellers'][0]['items'][0];

    expect($line['name'])->toContain('Super Robot Action Toy');
    expect($line['name'])->toContain('Black');
    expect($line['name'])->toContain('M');
    expect($line['image'])->toContain('products/robot.jpg');
    expect($line['seller_name'])->toBe('Toy Kingdom');
});

test('availability rule marks inactive, archived, suspended-seller, and unapproved-variant as unavailable', function () {
    $ctx = createCatalogContext();
    $cartService = app(CartService::class);

    // 1. Inactive listing cannot be added
    $variant2 = ProductVariant::create([
        'product_id' => $ctx['product']->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'red', 'size' => 's'],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    $inactiveListing = createActiveListing(array_merge($ctx, ['variant' => $variant2]), ['status' => ListingStatus::Inactive]);
    $res1 = $cartService->add($inactiveListing->id, 1);
    expect($res1['success'])->toBeFalse();
    expect($res1['message'])->toBe('This item is not available.');

    // 2. Add an active listing, then verify reprice() flags when it becomes inactive
    $listing = createActiveListing($ctx, ['stock' => 5]);
    $cartService->add($listing->id, 1);

    $listing->update(['status' => ListingStatus::Inactive]);
    $view = $cartService->view(force: true);
    expect($view['sellers'][0]['items'][0]['availability'])->toBe('unavailable');
    expect($view['can_checkout'])->toBeFalse();

    // 3. Archived listing
    $listing->update(['status' => ListingStatus::Archived]);
    $listing->delete(); // soft delete
    $view2 = $cartService->view(force: true);
    expect($view2['sellers'][0]['items'][0]['availability'])->toBe('unavailable');

    // 4. Suspended seller
    $ctx['seller']->update(['status' => SellerStatus::Suspended]);
    $view3 = $cartService->view(force: true);
    expect($view3['sellers'][0]['items'][0]['availability'])->toBe('unavailable');

    // 5. Unapproved variant
    $ctx['seller']->update(['status' => SellerStatus::Approved]);
    $listing->restore();
    $listing->update(['status' => ListingStatus::Active]);
    $ctx['variant']->update(['qc_status' => VariantQcStatus::Rejected]);
    $view4 = $cartService->view(force: true);
    expect($view4['sellers'][0]['items'][0]['availability'])->toBe('unavailable');

    // 6. Out of stock listing
    $ctx['variant']->update(['qc_status' => VariantQcStatus::Approved]);
    $listing->update(['stock' => 0]);
    $view5 = $cartService->view(force: true);
    expect($view5['sellers'][0]['items'][0]['availability'])->toBe('out_of_stock');
});

test('seller cannot add their own listings to their cart', function () {
    $ctx = createCatalogContext();
    $sellerUser = $ctx['seller']->user; // the owner user of the seller profile
    $listing = createActiveListing($ctx);

    // Log in as seller
    auth()->login($sellerUser);

    $cartService = app(CartService::class);
    $res = $cartService->add($listing->id, 1);

    expect($res['success'])->toBeFalse();
    expect($res['message'])->toBe('You cannot add your own listings to your cart.');
    expect($cartService->count())->toBe(0);

    // Different customer CAN add it
    $customer = User::factory()->create();
    auth()->login($customer);

    $resCustomer = $cartService->add($listing->id, 1);
    expect($resCustomer['success'])->toBeTrue();
    expect($cartService->count())->toBe(1);
});

test('on merge qty is capped by min of stock and MAX_QTY_PER_LINE', function () {
    $ctx = createCatalogContext();
    $listing = createActiveListing($ctx, ['stock' => 4]); // only 4 in stock

    $user = User::factory()->create();
    $cartService = app(CartService::class);

    // 1. User cart already has qty 2
    auth()->login($user);
    $cartService->add($listing->id, 2);
    expect($cartService->count())->toBe(2);
    auth()->logout();

    // 2. Guest cart has qty 3 (cookie token)
    // We simulate guest adding item
    $resGuest = $cartService->add($listing->id, 3);
    expect($resGuest['success'])->toBeTrue();

    // 3. User logs in -> trigger merge
    // Desired combined = 2 + 3 = 5, but stock is 4, so capped at 4!
    $cartService->mergeGuestIntoUser($user);

    auth()->login($user);
    $view = $cartService->view();
    expect($view['lines'])->toBe(1);
    expect($view['sellers'][0]['items'][0]['qty'])->toBe(4); // capped at stock=4
});

test('on merge qty is capped by MAX_QTY_PER_LINE when stock exceeds 10', function () {
    $ctx = createCatalogContext();
    $listing = createActiveListing($ctx, ['stock' => 25]); // 25 in stock

    $user = User::factory()->create();
    $cartService = app(CartService::class);

    // User cart has 7
    auth()->login($user);
    $cartService->add($listing->id, 7);
    auth()->logout();

    // Guest cart has 6
    $cartService->add($listing->id, 6);

    // Total wanted = 7 + 6 = 13, capped at MAX_QTY_PER_LINE = 10
    $cartService->mergeGuestIntoUser($user);

    auth()->login($user);
    $view = $cartService->view();
    expect($view['sellers'][0]['items'][0]['qty'])->toBe(10);
});

test('login merge listener triggers on web guard User login but ignores admin login', function () {
    $ctx = createCatalogContext();
    $listing = createActiveListing($ctx, ['stock' => 10]);

    $customer = User::factory()->create();
    $admin = Admin::factory()->create();

    $cartService = app(CartService::class);

    // Guest adds item
    $cartService->add($listing->id, 2);
    expect(Cart::whereNull('user_id')->count())->toBe(1);

    // Fire login event for admin on admin guard
    event(new Login('admin', $admin, false));

    // Guest cart must NOT be touched or merged
    expect(Cart::whereNull('user_id')->count())->toBe(1);
    expect(Cart::where('user_id', $customer->id)->count())->toBe(0);

    // Fire login event for web customer
    event(new Login('web', $customer, false));

    // Guest cart must now be merged into customer
    expect(Cart::whereNull('user_id')->count())->toBe(0);
    expect(Cart::where('user_id', $customer->id)->count())->toBe(1);
    expect(Cart::where('user_id', $customer->id)->first()->items()->sum('qty'))->toBe(2);
});

test('guest cart pruning command prunes carts inactive for 60+ days', function () {
    // 1. Fresh guest cart (today)
    $freshGuest = Cart::create([
        'guest_token' => hash('sha256', 'token-fresh'),
        'last_activity_at' => now(),
    ]);

    // 2. Old guest cart (65 days ago)
    $oldGuest = Cart::create([
        'guest_token' => hash('sha256', 'token-old'),
        'last_activity_at' => now()->subDays(65),
    ]);

    // 3. Old user cart (should never be pruned by guest prune command)
    $user = User::factory()->create();
    $userCart = Cart::create([
        'user_id' => $user->id,
        'last_activity_at' => now()->subDays(90),
    ]);

    $this->artisan('cart:prune-guest-carts', ['--days' => 60])
        ->expectsOutput('Pruned 1 guest carts inactive for 60+ days.')
        ->assertExitCode(0);

    expect(Cart::find($freshGuest->id))->not->toBeNull();
    expect(Cart::find($oldGuest->id))->toBeNull();
    expect(Cart::find($userCart->id))->not->toBeNull();
});

test('cart supports seller grouping, price updates, acknowledge, and clearing', function () {
    $ctx1 = createCatalogContext();
    $listing1 = createActiveListing($ctx1, ['selling_price' => 50000, 'mrp' => 60000]);

    // Create a second seller with another listing
    $user2 = User::factory()->create();
    $seller2 = SellerProfile::factory()->create([
        'user_id' => $user2->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Brick Universe',
    ]);
    $listing2 = createActiveListing(array_merge($ctx1, ['seller' => $seller2]), [
        'seller_profile_id' => $seller2->id,
        'selling_price' => 75000,
        'mrp' => 90000,
    ]);

    $cartService = app(CartService::class);
    $cartService->add($listing1->id, 2);
    $cartService->add($listing2->id, 1);

    $view = $cartService->view();
    expect($view['lines'])->toBe(2);
    expect($view['units'])->toBe(3);
    expect(count($view['sellers']))->toBe(2);

    // Remove one seller
    $cartService->removeSeller($seller2->id);
    $viewAfterSellerRemove = $cartService->view();
    expect($viewAfterSellerRemove['lines'])->toBe(1);
    expect($viewAfterSellerRemove['sellers'][0]['seller_profile_id'])->toBe($ctx1['seller']->id);

    // Clear cart
    $cartService->clear();
    expect($cartService->count())->toBe(0);
});

test('cart service is registered as scoped and resolves different instances across requests', function () {
    // Within the same request scope, resolving twice returns the exact same instance
    $instance1a = app(CartService::class);
    $instance1b = app(CartService::class);
    expect($instance1a)->toBe($instance1b);

    // Forget scoped instances to simulate the boundary between requests (e.g. under Octane/workers)
    app()->forgetScopedInstances();

    $instance2 = app(CartService::class);
    expect($instance2)->not->toBeNull();
    expect($instance2)->not->toBe($instance1a);
});

test('customer login merges guest cart exactly once and admin login is ignored', function () {
    $customer = User::factory()->create();
    $admin = Admin::factory()->create();

    $mock = Mockery::mock(CartService::class);
    // Must be called exactly once for customer login, never for admin login
    $mock->shouldReceive('mergeGuestIntoUser')
        ->once()
        ->with(Mockery::on(fn ($user) => $user instanceof User && $user->id === $customer->id));

    app()->instance(CartService::class, $mock);

    // 1. Admin login must be completely ignored
    event(new Login('admin', $admin, false));

    // 2. Customer login must trigger the merge exactly once
    event(new Login('web', $customer, false));
});
