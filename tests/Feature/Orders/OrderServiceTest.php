<?php

use App\Checkout\Models\CheckoutIntent;
use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Mail\CustomerOrderConfirmationMail;
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
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Services\OrderService;
use App\Services\CartService;
use App\Services\UpidGenerator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;

function setupOrderTestCatalog(): array
{
    $brand = Brand::create(['name' => 'ToyCraft', 'slug' => 'toycraft-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Toys', 'slug' => 'toys-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Action Figures', 'slug' => 'action-figures-'.uniqid(), 'hsn_code' => '9503', 'gst_rate' => 18.0]);

    $sellerUser = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $sellerUser->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Hero Toys',
        'gstin' => '29ABCDE1234F1Z5',
        'pickup_state' => 'Karnataka',
    ]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Superhero Figure',
        'slug' => 'superhero-figure-'.uniqid(),
        'description' => 'Quality collectible figure',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $image = ProductImage::create([
        'product_id' => $product->id,
        'path' => 'products/superhero.jpg',
        'is_primary' => true,
        'status' => ProductImageStatus::Live,
        'sort_order' => 1,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'blue', 'size' => 'm'],
        'attribute_snapshot' => [
            'color' => ['value' => 'blue', 'label' => 'Blue'],
            'size' => ['value' => 'm', 'label' => 'M'],
        ],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $listing = Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SKU-'.uniqid(),
        'mrp' => 200000,          // ₹2,000
        'selling_price' => 149900, // ₹1,499
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    return compact('brand', 'cat', 'vert', 'sellerUser', 'seller', 'product', 'image', 'variant', 'listing');
}

function createCustomerWithAddress(): array
{
    $customer = User::factory()->create();
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Rahul Sharma',
        'phone' => '9876543210',
        'pincode' => '560001',
        'locality' => 'MG Road',
        'address_line' => 'Flat 402, Sunshine Apts',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'landmark' => 'Near Metro Station',
        'type' => 'home',
        'is_default' => true,
    ]);

    return compact('customer', 'address');
}

test('COD order from cart decrements stock, creates order and seller orders with tax snapshot, and clears cart lines', function () {
    $ctx = setupOrderTestCatalog();
    ['customer' => $customer, 'address' => $address] = createCustomerWithAddress();

    // 1. Add to cart
    $cartService = app(CartService::class);
    $this->actingAs($customer);
    $cartService->add($ctx['listing']->id, 2);

    expect($customer->cart->items)->toHaveCount(1);
    expect($ctx['listing']->fresh()->stock)->toBe(10);

    // 2. Prepare PlaceOrderData
    $orderService = app(OrderService::class);
    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 2],
        ],
        expectedTotal: 149900 * 2, // ₹2,998
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    );

    $order = $orderService->place($placeData);

    // 3. Assertions on Order
    expect($order)->toBeInstanceOf(Order::class);
    expect($order->status)->toBe(OrderStatus::Placed);
    expect($order->payment_method)->toBe(PaymentMethod::CashOnDelivery);
    expect($order->payment_status)->toBe(PaymentStatus::Pending);
    expect($order->grand_total)->toBe(299800);
    expect($order->items_mrp_total)->toBe(400000);
    expect($order->discount_total)->toBe(100200);
    expect($order->delivery_state)->toBe('Karnataka');
    expect($order->delivery_address['name'])->toBe('Rahul Sharma');
    expect($order->order_number)->toStartWith('KH-');

    // 4. Assert stock was decremented
    expect($ctx['listing']->fresh()->stock)->toBe(8);

    // 5. Assert SellerOrder and OrderItems
    $sellerOrders = $order->sellerOrders;
    expect($sellerOrders)->toHaveCount(1);

    $sellerOrder = $sellerOrders->first();
    expect($sellerOrder->seller_profile_id)->toBe($ctx['seller']->id);
    expect($sellerOrder->seller_store_name)->toBe('Hero Toys');
    expect($sellerOrder->seller_gstin)->toBe('29ABCDE1234F1Z5');
    expect($sellerOrder->seller_pickup_state)->toBe('Karnataka');
    expect($sellerOrder->status)->toBe(SellerOrderStatus::Placed);
    expect($sellerOrder->subtotal)->toBe(299800);

    $items = $sellerOrder->items;
    expect($items)->toHaveCount(1);
    $item = $items->first();
    expect($item->title)->toBe('Superhero Figure');
    expect($item->variant_title)->toContain('Blue');
    expect($item->hsn_code)->toBe('9503');
    expect($item->gst_rate)->toEqual(18.0);
    expect($item->unit_price)->toBe(149900);
    expect($item->qty)->toBe(2);
    expect($item->line_total)->toBe(299800);
    expect($item->gst_amount)->toBeGreaterThan(0);

    // 6. Assert cart line was cleared by event listener
    expect($customer->fresh()->cart->items)->toHaveCount(0);
});

test('COD order from Buy Now decrements stock and does NOT clear customer cart', function () {
    $ctx1 = setupOrderTestCatalog();
    $ctx2 = setupOrderTestCatalog();
    ['customer' => $customer, 'address' => $address] = createCustomerWithAddress();

    // Customer has ctx1 in their cart
    $cartService = app(CartService::class);
    $this->actingAs($customer);
    $cartService->add($ctx1['listing']->id, 1);
    expect($customer->cart->items)->toHaveCount(1);

    // Now places Buy Now order for ctx2
    $orderService = app(OrderService::class);
    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx2['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 149900,
        idempotencyKey: (string) Str::ulid(),
        source: 'buy_now',
    );

    $order = $orderService->place($placeData);

    expect($order->status)->toBe(OrderStatus::Placed);
    expect($ctx2['listing']->fresh()->stock)->toBe(9);

    // Customer's cart still has ctx1 intact!
    expect($customer->fresh()->cart->items)->toHaveCount(1);
    expect($customer->cart->items->first()->listing_id)->toBe($ctx1['listing']->id);
});

test('cannot place order with address belonging to another user', function () {
    $ctx = setupOrderTestCatalog();
    ['customer' => $customer] = createCustomerWithAddress();
    ['address' => $otherAddress] = createCustomerWithAddress(); // Belongs to different user

    $orderService = app(OrderService::class);
    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $otherAddress->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 149900,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    );

    expect(fn () => $orderService->place($placeData))
        ->toThrow(DomainException::class, 'The selected delivery address does not belong to your account.');
});

test('price change guard rejects order placement if expected total does not match live total', function () {
    $ctx = setupOrderTestCatalog();
    ['customer' => $customer, 'address' => $address] = createCustomerWithAddress();

    $orderService = app(OrderService::class);
    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 100000, // Stale price: ₹1,000 instead of live ₹1,499
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    );

    expect(fn () => $orderService->place($placeData))
        ->toThrow(DomainException::class, 'Price has changed since your checkout session started.');
});

test('order placement is idempotent on duplicate submission with same idempotency key', function () {
    $ctx = setupOrderTestCatalog();
    ['customer' => $customer, 'address' => $address] = createCustomerWithAddress();

    $orderService = app(OrderService::class);
    $idempotencyKey = (string) Str::ulid();

    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 149900,
        idempotencyKey: $idempotencyKey,
        source: 'cart',
    );

    // First call
    $order1 = $orderService->place($placeData);
    expect($ctx['listing']->fresh()->stock)->toBe(9);

    // Second call with identical key
    $order2 = $orderService->place($placeData);

    expect($order2->id)->toBe($order1->id);
    expect($order2->order_number)->toBe($order1->order_number);
    // Stock must NOT be decremented again
    expect($ctx['listing']->fresh()->stock)->toBe(9);
    // Only 1 order exists in database
    expect(Order::where('checkout_intent_id', $idempotencyKey)->count())->toBe(1);
});

test('seller cannot purchase their own listing', function () {
    $ctx = setupOrderTestCatalog();
    // Use seller user to create address
    $address = CustomerAddress::create([
        'user_id' => $ctx['sellerUser']->id,
        'name' => 'Seller Buyer',
        'phone' => '9876543210',
        'pincode' => '560001',
        'locality' => 'MG Road',
        'address_line' => 'Flat 1',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'type' => 'home',
        'is_default' => true,
    ]);

    $orderService = app(OrderService::class);
    $placeData = new PlaceOrderData(
        userId: $ctx['sellerUser']->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 149900,
        idempotencyKey: (string) Str::ulid(),
        source: 'buy_now',
    );

    expect(fn () => $orderService->place($placeData))
        ->toThrow(DomainException::class, 'You cannot purchase your own listings.');
});

test('order placement rejects unapproved variant or inactive listing', function () {
    $ctx = setupOrderTestCatalog();
    ['customer' => $customer, 'address' => $address] = createCustomerWithAddress();

    $ctx['listing']->update(['status' => ListingStatus::Inactive]);

    $orderService = app(OrderService::class);
    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 149900,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    );

    expect(fn () => $orderService->place($placeData))
        ->toThrow(DomainException::class, 'no longer available');
});

test('livewire checkout summary confirms COD order and redirects to order success page', function () {
    $ctx = setupOrderTestCatalog();
    ['customer' => $customer, 'address' => $address] = createCustomerWithAddress();

    $intent = CheckoutIntent::create([
        'id' => (string) Str::ulid(),
        'user_id' => $customer->id,
        'source' => 'cart',
        'lines' => [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        'status' => 'pending',
        'expires_at' => now()->addMinutes(30),
    ]);

    $this->actingAs($customer);

    Livewire::test('pages::storefront.checkout-summary', ['intent' => $intent->id])
        ->set('selectedAddressId', $address->id)
        ->call('confirmOrder')
        ->assertRedirect();

    $order = Order::where('checkout_intent_id', $intent->id)->first();
    expect($order)->not->toBeNull();
    expect($order->status)->toBe(OrderStatus::Placed);
    expect($intent->fresh()->status)->toBe('completed');
});

test('queued mailables: customer confirmation and seller notification are queued after commit', function () {
    Mail::fake();

    $ctx = setupOrderTestCatalog();
    ['customer' => $customer, 'address' => $address] = createCustomerWithAddress();

    $orderService = app(OrderService::class);
    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 149900,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    );

    $order = $orderService->place($placeData);

    Mail::assertQueued(CustomerOrderConfirmationMail::class, function ($mail) use ($customer, $order) {
        return $mail->hasTo($customer->email) && $mail->order->id === $order->id;
    });

    Mail::assertQueued(SellerNewOrderMail::class, function ($mail) use ($ctx) {
        return $mail->hasTo($ctx['sellerUser']->email);
    });
});

test('checkout summary rejects placing order with address belonging to another user', function () {
    $ctx = setupOrderTestCatalog();
    ['customer' => $customer, 'address' => $address] = createCustomerWithAddress();
    ['address' => $otherAddress] = createCustomerWithAddress();

    $intent = CheckoutIntent::create([
        'id' => (string) Str::ulid(),
        'user_id' => $customer->id,
        'source' => 'cart',
        'lines' => [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        'status' => 'pending',
        'expires_at' => now()->addMinutes(30),
    ]);

    $this->actingAs($customer);

    Livewire::test('pages::storefront.checkout-summary', ['intent' => $intent->id])
        ->set('selectedAddressId', $otherAddress->id)
        ->call('confirmOrder')
        ->assertSee('The selected delivery address does not belong to your account.');

    expect(Order::where('checkout_intent_id', $intent->id)->exists())->toBeFalse();
});

test('reopening completed checkout intent URL redirects to the order details page', function () {
    $ctx = setupOrderTestCatalog();
    ['customer' => $customer, 'address' => $address] = createCustomerWithAddress();

    $intent = CheckoutIntent::create([
        'id' => (string) Str::ulid(),
        'user_id' => $customer->id,
        'source' => 'cart',
        'lines' => [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        'status' => 'completed',
        'expires_at' => now()->addMinutes(30),
    ]);

    $order = Order::create([
        'order_number' => 'KH-20261024-COMP01',
        'user_id' => $customer->id,
        'checkout_intent_id' => $intent->id,
        'status' => OrderStatus::Placed,
        'delivery_address' => $address->toSnapshot(),
        'delivery_state' => $address->state,
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending,
        'items_mrp_total' => 200000,
        'discount_total' => 50100,
        'delivery_fee' => 0,
        'grand_total' => 149900,
        'placed_at' => now(),
    ]);

    $this->actingAs($customer);

    Livewire::test('pages::storefront.checkout-summary', ['intent' => $intent->id])
        ->assertRedirect(route('orders.show', ['order' => $order->order_number]));
});

test('price change blocks placement until reconfirmed with live price', function () {
    $ctx = setupOrderTestCatalog();
    ['customer' => $customer, 'address' => $address] = createCustomerWithAddress();

    $orderService = app(OrderService::class);
    $idempotencyKey = (string) Str::ulid();

    // 1. Stale price: ₹1,000 expected but listing is ₹1,499
    $stalePlaceData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 100000, // Stale: ₹1,000
        idempotencyKey: $idempotencyKey,
        source: 'cart',
    );

    expect(fn () => $orderService->place($stalePlaceData))
        ->toThrow(DomainException::class, 'Price has changed since your checkout session started. Live total is ₹1,499.');

    // No order was created
    expect(Order::where('checkout_intent_id', $idempotencyKey)->exists())->toBeFalse();

    // 2. Reconfirmed with the live price: ₹1,499 -> succeeds!
    $reconfirmedPlaceData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 149900, // Live: ₹1,499
        idempotencyKey: $idempotencyKey,
        source: 'cart',
    );

    $order = $orderService->place($reconfirmedPlaceData);
    expect($order)->toBeInstanceOf(Order::class);
    expect($order->grand_total)->toBe(149900);
    expect($order->status)->toBe(OrderStatus::Placed);
});
