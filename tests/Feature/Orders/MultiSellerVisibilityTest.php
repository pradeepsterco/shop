<?php

use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Mail\SellerNewOrderMail;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Orders\DTOs\PlaceOrderData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Services\OrderService;
use App\Services\UpidGenerator;
use Illuminate\Support\Str;
use Livewire\Livewire;

function setupMultiSellerVisibilityContext(): array
{
    $brand = Brand::create(['name' => 'MultiBrand', 'slug' => 'multibrand-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Toys', 'slug' => 'toys-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Action Toys', 'slug' => 'action-toys-'.uniqid(), 'hsn_code' => '9503', 'gst_rate' => 18.0]);

    // Seller A
    $sellerAUser = User::factory()->create();
    $sellerA = SellerProfile::factory()->create([
        'user_id' => $sellerAUser->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Alpha Toys Store',
        'gstin' => '27AAAAA1111A1Z1',
        'pickup_state' => 'Maharashtra',
    ]);

    // Seller B
    $sellerBUser = User::factory()->create();
    $sellerB = SellerProfile::factory()->create([
        'user_id' => $sellerBUser->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Beta Hobby Shop',
        'gstin' => '29BBBBB2222B1Z2',
        'pickup_state' => 'Karnataka',
    ]);

    // Product 1 (Seller A's item)
    $prodA = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Alpha Robot Warrior',
        'slug' => 'alpha-robot-'.uniqid(),
        'description' => 'Cool robot',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    ProductImage::create(['product_id' => $prodA->id, 'path' => 'products/robot.jpg', 'is_primary' => true, 'status' => ProductImageStatus::Live, 'sort_order' => 1]);
    $varA = ProductVariant::create([
        'product_id' => $prodA->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['edition' => 'Red'],
        'attribute_snapshot' => ['edition' => ['value' => 'Red', 'label' => 'Red Edition']],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    $listingA = Listing::create([
        'seller_profile_id' => $sellerA->id,
        'product_variant_id' => $varA->id,
        'sku' => 'SKU-ALPHA-ROBOT',
        'mrp' => 200000,
        'selling_price' => 149900,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    // Product 2 (Seller B's item)
    $prodB = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Beta Racing Drone',
        'slug' => 'beta-drone-'.uniqid(),
        'description' => 'Fast drone',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    ProductImage::create(['product_id' => $prodB->id, 'path' => 'products/drone.jpg', 'is_primary' => true, 'status' => ProductImageStatus::Live, 'sort_order' => 1]);
    $varB = ProductVariant::create([
        'product_id' => $prodB->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'Blue'],
        'attribute_snapshot' => ['color' => ['value' => 'Blue', 'label' => 'Blue']],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    $listingB = Listing::create([
        'seller_profile_id' => $sellerB->id,
        'product_variant_id' => $varB->id,
        'sku' => 'SKU-BETA-DRONE',
        'mrp' => 300000,
        'selling_price' => 249900,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    // Customer
    $customer = User::factory()->create();
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Rahul Sharma',
        'phone' => '9876543210',
        'pincode' => '400001',
        'locality' => 'Fort Area',
        'address_line' => '42 Marine Lines',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'landmark' => 'Opposite Station',
        'alternate_phone' => '9811122233',
        'type' => 'home',
        'is_default' => true,
    ]);

    // Place multi-seller order: 2 units of A (₹1,499 each = ₹2,998) + 1 unit of B (₹2,499 = ₹2,499) -> Total = ₹5,497
    $orderService = app(OrderService::class);
    $order = $orderService->place(new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $listingA->id, 'qty' => 2],
            ['listing_id' => $listingB->id, 'qty' => 1],
        ],
        expectedTotal: 299800 + 249900,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    $sellerOrderA = $order->sellerOrders()->where('seller_profile_id', $sellerA->id)->firstOrFail();
    $sellerOrderB = $order->sellerOrders()->where('seller_profile_id', $sellerB->id)->firstOrFail();

    return compact(
        'sellerAUser', 'sellerA', 'listingA', 'prodA', 'sellerOrderA',
        'sellerBUser', 'sellerB', 'listingB', 'prodB', 'sellerOrderB',
        'customer', 'address', 'order'
    );
}

test('multi-seller order creates one parent order and distinct seller orders with separate items and totals', function () {
    $ctx = setupMultiSellerVisibilityContext();
    $order = $ctx['order'];
    $soA = $ctx['sellerOrderA'];
    $soB = $ctx['sellerOrderB'];

    // 1 Parent order
    expect($order->status)->toBe(OrderStatus::Placed);
    expect($order->grand_total)->toBe(549700);
    expect($order->sellerOrders)->toHaveCount(2);

    // Seller Order A has only A's items and totals
    expect($soA->seller_store_name)->toBe('Alpha Toys Store');
    expect($soA->subtotal)->toBe(299800);
    expect($soA->grand_total)->toBe(299800); // COD amount to collect for Package A
    expect($soA->items)->toHaveCount(1);
    expect($soA->items->first()->title)->toBe('Alpha Robot Warrior');
    expect($soA->items->first()->qty)->toBe(2);
    expect($soA->items->first()->line_total)->toBe(299800);

    // Seller Order B has only B's items and totals
    expect($soB->seller_store_name)->toBe('Beta Hobby Shop');
    expect($soB->subtotal)->toBe(249900);
    expect($soB->grand_total)->toBe(249900); // COD amount to collect for Package B
    expect($soB->items)->toHaveCount(1);
    expect($soB->items->first()->title)->toBe('Beta Racing Drone');
    expect($soB->items->first()->qty)->toBe(1);
    expect($soB->items->first()->line_total)->toBe(249900);
});

test('seller A list, detail page and email show only A items and totals, never B information', function () {
    $ctx = setupMultiSellerVisibilityContext();

    // 1. Seller A's Order List
    $this->actingAs($ctx['sellerAUser']);
    Livewire::test('pages::seller.orders-index')
        ->assertSee('₹2,998')
        ->assertDontSee('₹2,499')
        ->assertDontSee('₹5,497')
        ->assertDontSee('Beta Hobby Shop');

    // 2. Seller A's Order Detail Page
    Livewire::test('pages::seller.orders-show', ['sellerOrder' => $ctx['sellerOrderA']->id])
        ->assertSee('Alpha Robot Warrior')
        ->assertSee('SKU-ALPHA-ROBOT')
        ->assertSee('₹2,998')
        ->assertDontSee('Beta Racing Drone')
        ->assertDontSee('SKU-BETA-DRONE')
        ->assertDontSee('Beta Hobby Shop')
        ->assertDontSee('₹5,497')
        // Shows delivery address and phone for shipping
        ->assertSee('Rahul Sharma')
        ->assertSee('9876543210')
        ->assertSee('42 Marine Lines')
        ->assertSee('Fort Area')
        ->assertSee('400001');

    // 3. Seller A's New Order Email
    $mailable = new SellerNewOrderMail($ctx['sellerOrderA']);
    $mailable->assertSeeInHtml('Alpha Robot Warrior');
    $mailable->assertSeeInHtml('SKU-ALPHA-ROBOT');
    $mailable->assertSeeInHtml('₹2,998');
    $mailable->assertDontSeeInHtml('Beta Racing Drone');
    $mailable->assertDontSeeInHtml('Beta Hobby Shop');
    $mailable->assertDontSeeInHtml('₹5,497');
});

test('seller A receives 403 when attempting to access or manipulate seller B order', function () {
    $ctx = setupMultiSellerVisibilityContext();

    $this->actingAs($ctx['sellerAUser']);

    // Page view 403
    Livewire::test('pages::seller.orders-show', ['sellerOrder' => $ctx['sellerOrderB']->id])
        ->assertForbidden();

    // Direct Livewire action calls
    $this->actingAs($ctx['sellerBUser']);
    $bComp1 = Livewire::test('pages::seller.orders-show', ['sellerOrder' => $ctx['sellerOrderB']->id]);
    $this->actingAs($ctx['sellerAUser']);
    $bComp1->call('confirmOrder')->assertForbidden();

    $this->actingAs($ctx['sellerBUser']);
    $bComp2 = Livewire::test('pages::seller.orders-show', ['sellerOrder' => $ctx['sellerOrderB']->id]);
    $this->actingAs($ctx['sellerAUser']);
    $bComp2->call('markPacked')->assertForbidden();

    $this->actingAs($ctx['sellerBUser']);
    $bComp3 = Livewire::test('pages::seller.orders-show', ['sellerOrder' => $ctx['sellerOrderB']->id]);
    $this->actingAs($ctx['sellerAUser']);
    $bComp3->call('confirmCancel')->assertForbidden();
});

test('seller order list search filters strictly over own seller orders', function () {
    $ctx = setupMultiSellerVisibilityContext();

    $this->actingAs($ctx['sellerAUser']);

    // Searching for Seller A's item title or SKU finds Seller A's order
    Livewire::test('pages::seller.orders-index')
        ->set('search', 'Alpha Robot')
        ->assertSee($ctx['order']->order_number)
        ->assertSee('₹2,998');

    // Searching for Seller B's item title or SKU returns 0 results for Seller A
    Livewire::test('pages::seller.orders-index')
        ->set('search', 'Beta Racing')
        ->assertDontSee($ctx['order']->order_number)
        ->assertSee('No orders found');

    Livewire::test('pages::seller.orders-index')
        ->set('search', 'SKU-BETA-DRONE')
        ->assertDontSee($ctx['order']->order_number)
        ->assertSee('No orders found');
});
