<?php

use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
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
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Services\OrderService;
use App\Services\UpidGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function setupMultiSellerContext(): array
{
    $brand = Brand::create(['name' => 'ToyBrand', 'slug' => 'toybrand-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Toys', 'slug' => 'toys-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Blocks', 'slug' => 'blocks-'.uniqid(), 'hsn_code' => '9503', 'gst_rate' => 12.0]);

    // Seller 1
    $seller1User = User::factory()->create();
    $seller1 = SellerProfile::factory()->create([
        'user_id' => $seller1User->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Alpha Toys',
        'gstin' => '27AAACA1111A1Z1',
        'pickup_state' => 'Maharashtra',
    ]);

    // Seller 2
    $seller2User = User::factory()->create();
    $seller2 = SellerProfile::factory()->create([
        'user_id' => $seller2User->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Beta Toys',
        'gstin' => '29AAACB2222B1Z2',
        'pickup_state' => 'Karnataka',
    ]);

    // Product 1 (Seller 1)
    $p1 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Building Blocks Set',
        'slug' => 'blocks-set-'.uniqid(),
        'description' => 'Colorful building blocks',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    ProductImage::create([
        'product_id' => $p1->id,
        'path' => 'products/p1.jpg',
        'is_primary' => true,
        'status' => ProductImageStatus::Live,
        'sort_order' => 1,
    ]);
    $v1 = ProductVariant::create([
        'product_id' => $p1->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['pieces' => '100'],
        'attribute_snapshot' => ['pieces' => ['value' => '100', 'label' => '100 Pcs']],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    $l1 = Listing::create([
        'seller_profile_id' => $seller1->id,
        'product_variant_id' => $v1->id,
        'sku' => 'SKU-P1-'.uniqid(),
        'mrp' => 100000,
        'selling_price' => 80000,
        'stock' => 5,
        'status' => ListingStatus::Active,
    ]);

    // Product 2 (Seller 2)
    $p2 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Magnetic Tiles Set',
        'slug' => 'tiles-set-'.uniqid(),
        'description' => 'Magnetic tiles',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    ProductImage::create([
        'product_id' => $p2->id,
        'path' => 'products/p2.jpg',
        'is_primary' => true,
        'status' => ProductImageStatus::Live,
        'sort_order' => 1,
    ]);
    $v2 = ProductVariant::create([
        'product_id' => $p2->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['pieces' => '50'],
        'attribute_snapshot' => ['pieces' => ['value' => '50', 'label' => '50 Pcs']],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    $l2 = Listing::create([
        'seller_profile_id' => $seller2->id,
        'product_variant_id' => $v2->id,
        'sku' => 'SKU-P2-'.uniqid(),
        'mrp' => 150000,
        'selling_price' => 120000,
        'stock' => 3,
        'status' => ListingStatus::Active,
    ]);

    $customer = User::factory()->create();
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Priya Patel',
        'phone' => '9876501234',
        'pincode' => '400001',
        'locality' => 'Fort',
        'address_line' => '50 Flora Fountain',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'type' => 'home',
        'is_default' => true,
    ]);

    return compact('seller1', 'seller2', 'l1', 'l2', 'customer', 'address');
}

test('customer can cancel seller order when placed or confirmed, which restocks stock and syncs order status', function () {
    $ctx = setupMultiSellerContext();
    $orderService = app(OrderService::class);

    // Single seller order
    $order = $orderService->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['l1']->id, 'qty' => 2],
        ],
        expectedTotal: 160000,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    expect($ctx['l1']->fresh()->stock)->toBe(3); // 5 - 2 = 3
    $sellerOrder = $order->sellerOrders->first();

    // Cancel by customer
    $orderService->cancelSellerOrder($sellerOrder, 'customer', $ctx['customer']->id, 'Placed by mistake');

    $sellerOrder->refresh();
    expect($sellerOrder->status)->toBe(SellerOrderStatus::Cancelled);
    expect($sellerOrder->cancellation_reason)->toBe('Placed by mistake');
    expect($sellerOrder->cancelled_by_type)->toBe('customer');

    // Stock must be restocked
    expect($ctx['l1']->fresh()->stock)->toBe(5);

    // Parent order must become Cancelled and COD payment_status must become Cancelled
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Cancelled);
    expect($order->payment_status)->toBe(PaymentStatus::Cancelled);

    // Audit trail logged
    $histories = $sellerOrder->statusHistories;
    expect($histories->count())->toBeGreaterThanOrEqual(2); // Placed + Cancelled
    expect($histories->last()->to_status)->toBe('cancelled');
});

test('customer cannot cancel seller order once packed or shipped', function () {
    $ctx = setupMultiSellerContext();
    $orderService = app(OrderService::class);

    $order = $orderService->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['l1']->id, 'qty' => 1],
        ],
        expectedTotal: 80000,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    $sellerOrder = $order->sellerOrders->first();
    $orderService->transitionSellerOrderStatus($sellerOrder, SellerOrderStatus::Packed, 'seller', 1, 'Packed package');

    expect(fn () => $orderService->cancelSellerOrder($sellerOrder, 'customer', $ctx['customer']->id, 'Change mind'))
        ->toThrow(DomainException::class, 'This order cannot be cancelled as it has already been processed.');
});

test('multi-seller order: cancelling one package marks parent order partially_cancelled', function () {
    $ctx = setupMultiSellerContext();
    $orderService = app(OrderService::class);

    $order = $orderService->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['l1']->id, 'qty' => 1], // Seller 1 (80000)
            ['listing_id' => $ctx['l2']->id, 'qty' => 1], // Seller 2 (120000)
        ],
        expectedTotal: 200000,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    expect($order->sellerOrders)->toHaveCount(2);
    expect($order->status)->toBe(OrderStatus::Placed);

    $seller1Order = $order->sellerOrders->firstWhere('seller_profile_id', $ctx['seller1']->id);
    $seller2Order = $order->sellerOrders->firstWhere('seller_profile_id', $ctx['seller2']->id);

    // Cancel Seller 1 order
    $orderService->cancelSellerOrder($seller1Order, 'customer', $ctx['customer']->id, 'Item not needed');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::PartiallyCancelled);
    // Payment status is still pending because Seller 2 order is active
    expect($order->payment_status)->toBe(PaymentStatus::Pending);

    // Now cancel Seller 2 order as well
    $orderService->cancelSellerOrder($seller2Order, 'seller', $ctx['seller2']->id, 'Out of stock');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Cancelled);
    expect($order->payment_status)->toBe(PaymentStatus::Cancelled);
});

test('seller fulfillment workflow transitions placed -> confirmed -> packed -> shipped -> delivered', function () {
    $ctx = setupMultiSellerContext();
    $orderService = app(OrderService::class);

    $order = $orderService->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['l1']->id, 'qty' => 1],
        ],
        expectedTotal: 80000,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    $sellerOrder = $order->sellerOrders->first();
    $sellerId = $ctx['seller1']->id;

    // Placed -> Confirmed
    $orderService->transitionSellerOrderStatus($sellerOrder, SellerOrderStatus::Confirmed, 'seller', $sellerId);
    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Confirmed);
    expect($sellerOrder->fresh()->confirmed_at)->not->toBeNull();

    // Confirmed -> Packed
    $orderService->transitionSellerOrderStatus($sellerOrder, SellerOrderStatus::Packed, 'seller', $sellerId);
    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Packed);
    expect($sellerOrder->fresh()->packed_at)->not->toBeNull();

    // Packed -> Shipped
    $orderService->transitionSellerOrderStatus($sellerOrder, SellerOrderStatus::Shipped, 'seller', $sellerId);
    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Shipped);
    expect($sellerOrder->fresh()->shipped_at)->not->toBeNull();

    // Shipped -> Delivered
    $orderService->transitionSellerOrderStatus($sellerOrder, SellerOrderStatus::Delivered, 'seller', $sellerId);
    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Delivered);
    expect($sellerOrder->fresh()->delivered_at)->not->toBeNull();

    // Parent order must become Completed and COD payment_status must become Completed
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Completed);
    expect($order->payment_status)->toBe(PaymentStatus::Completed);
});

test('concurrency simulation: race condition between validation and atomic decrement rolls back cleanly', function () {
    $ctx = setupMultiSellerContext();
    $orderService = app(OrderService::class);

    // Initial stock is 1
    $ctx['l1']->update(['stock' => 1]);

    $idempotencyKey = (string) Str::ulid();
    $placeData = new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['l1']->id, 'qty' => 1],
        ],
        expectedTotal: 80000,
        idempotencyKey: $idempotencyKey,
        source: 'cart',
    );

    // Simulate concurrent purchase right before atomic decrement:
    // We attach a DB listener or simulate stock depletion.
    // In our implementation, `Listing::where('id', $listingId)->where('stock', '>=', $qty)->decrement('stock', $qty)`
    // guards the update. If another connection decremented stock to 0, affected rows is 0.
    DB::beforeExecuting(function ($query, $bindings) use ($ctx) {
        // If the query is locking or about to decrement, deplete the stock once to simulate race condition
        static $depleted = false;
        if (! $depleted && str_contains(strtolower($query), 'update "listings" set "stock" = "stock" -')) {
            $depleted = true;
            // Another thread bought the last item right before this update!
            DB::table('listings')->where('id', $ctx['l1']->id)->update(['stock' => 0]);
        }
    });

    expect(fn () => $orderService->place($placeData))
        ->toThrow(DomainException::class, 'Stock changed during checkout. One or more items are no longer available in the requested quantity.');

    // Transaction was rolled back: no order was created
    expect(Order::where('checkout_intent_id', $idempotencyKey)->exists())->toBeFalse();
});
