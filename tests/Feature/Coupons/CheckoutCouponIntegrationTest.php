<?php

use App\Checkout\Models\CheckoutIntent;
use App\Checkout\Services\CheckoutIntentService;
use App\Coupons\Contracts\CouponEvaluatorInterface;
use App\Coupons\DTOs\CartContext;
use App\Coupons\DTOs\CartLineContext;
use App\Coupons\Enums\CouponFundedBy;
use App\Coupons\Enums\CouponStatus;
use App\Coupons\Enums\DiscountType;
use App\Coupons\Models\Coupon;
use App\Coupons\Models\CouponRedemption;
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
use App\Orders\Contracts\SellerOrderFulfillmentInterface;
use App\Orders\DTOs\PlaceOrderData;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\OrderService;
use App\Orders\Services\SellerOrderShippingDetailsLookupService;

function setupCouponCheckoutCatalog(): array
{
    $brand = Brand::create(['name' => 'TechBrand', 'slug' => 'tech-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Electronics', 'slug' => 'elec-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Audio', 'slug' => 'audio-'.uniqid(), 'hsn_code' => '8518', 'gst_rate' => 18.0]);

    $sellerUser = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $sellerUser->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Audio Store',
        'gstin' => '29ABCDE1234F1Z5',
        'pickup_state' => 'Karnataka',
    ]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Wireless Earbuds',
        'slug' => 'earbuds-'.uniqid(),
        'description' => 'Quality wireless earbuds',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    ProductImage::create([
        'product_id' => $product->id,
        'path' => 'products/earbuds.jpg',
        'status' => ProductImageStatus::Live,
        'is_primary' => true,
    ]);

    $sku = 'SKU-EARBUDS-'.uniqid();
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'variant_key' => 'default',
        'variant_attributes' => [],
        'upid' => 'UPID-'.uniqid(),
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $listing = Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $variant->id,
        'sku' => $sku,
        'mrp' => 100000,          // ₹1,000 MRP
        'selling_price' => 80000, // ₹800 Selling Price
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    return compact('brand', 'cat', 'vert', 'seller', 'product', 'variant', 'listing');
}

test('order placement with coupon applies discount, snapshots on items, and satisfies invariant', function () {
    $catalog = setupCouponCheckoutCatalog();
    $listing = $catalog['listing'];

    $customer = User::factory()->create();
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Rahul Sharma',
        'phone' => '9876543210',
        'pincode' => '560001',
        'locality' => 'Indiranagar',
        'address_line' => '123 Main Road',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'type' => 'home',
        'is_default' => true,
    ]);

    // Create 20% coupon
    $coupon = Coupon::create([
        'code' => 'SAVE20',
        'name' => '20% Off',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20, // 20%
        'min_order_amount' => 50000, // ₹500 min spend
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $orderService = app(OrderService::class);

    // Order 1 unit @ ₹800 (80,000 paise)
    // 20% discount = ₹160 (16,000 paise)
    // Expected grand total = 80,000 - 16,000 = 64,000 paise (₹640.00)
    $lines = [
        ['listing_id' => $listing->id, 'qty' => 1],
    ];

    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: $lines,
        expectedTotal: 64000,
        idempotencyKey: 'intent_coupon_123',
        source: 'cart',
        couponCode: 'SAVE20',
    );

    $order = $orderService->place($placeData);

    // 1. Verify Order attributes
    expect($order->coupon_code)->toBe('SAVE20')
        ->and($order->coupon_discount_total)->toBe(16000)
        ->and($order->grand_total)->toBe(64000);

    // 2. Rule 6: Invariant assertion
    // grand_total = items_subtotal - coupon_discount_total + shipping_fee
    $itemsSubtotal = 80000;
    $shippingFee = 0;
    expect($order->grand_total)->toBe($itemsSubtotal - $order->coupon_discount_total + $shippingFee);

    // 3. Verify SellerOrder snapshot
    $sellerOrder = $order->sellerOrders()->first();
    expect($sellerOrder->coupon_discount_total)->toBe(16000)
        ->and($sellerOrder->subtotal)->toBe(80000)
        ->and($sellerOrder->grand_total)->toBe(64000);

    // 4. Verify OrderItem snapshots
    $orderItem = $sellerOrder->items()->first();
    expect($orderItem->coupon_discount_amount)->toBe(16000)
        ->and($orderItem->coupon_funded_by)->toBe('platform');

    // Item discount sum invariant
    expect((int) $order->sellerOrders->flatMap->items->sum('coupon_discount_amount'))
        ->toBe($order->coupon_discount_total);

    // 5. Verify CouponRedemption record created
    $redemption = CouponRedemption::where('idempotency_key', 'intent_coupon_123')->first();
    expect($redemption)->not->toBeNull()
        ->and($redemption->coupon_id)->toBe($coupon->id)
        ->and($redemption->discount_amount)->toBe(16000)
        ->and($redemption->order_reference)->toBe($order->order_number);
});

test('cart mutation dropping below min spend removes coupon from checkout intent', function () {
    $catalog = setupCouponCheckoutCatalog();
    $listing = $catalog['listing'];

    $customer = User::factory()->create();

    // Coupon with minimum spend of ₹1,000 (100,000 paise)
    $coupon = Coupon::create([
        'code' => 'MIN1000',
        'name' => 'Min 1000',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 20000,
        'min_order_amount' => 100000,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $checkoutService = app(CheckoutIntentService::class);

    // Create intent with 2 units of ₹800 = ₹1,600 (meets ₹1,000 threshold)
    $intent = CheckoutIntent::create([
        'user_id' => $customer->id,
        'source' => 'cart',
        'lines' => [
            [
                'listing_id' => $listing->id,
                'qty' => 2,
                'unit_price' => 80000,
            ],
        ],
        'status' => 'pending',
        'coupon_code' => 'MIN1000',
        'expires_at' => now()->addMinutes(30),
    ]);

    // 1st evaluation with 2 units: valid!
    $eval1 = $checkoutService->evaluateIntent($intent);
    expect($eval1['summary']['coupon_discount'])->toBe(20000)
        ->and($eval1['coupon_revoked'])->toBeFalse()
        ->and($intent->fresh()->coupon_code)->toBe('MIN1000');

    // User reduces quantity to 1 unit = ₹800 (now below ₹1,000 threshold!)
    $checkoutService->updateLineQty($intent, $listing->id, 1);

    // 2nd evaluation: coupon becomes invalid, is removed, and revoked info returned
    $eval2 = $checkoutService->evaluateIntent($intent);
    expect($eval2['coupon_revoked'])->toBeTrue()
        ->and($eval2['coupon_revoked_reason'])->toContain("Coupon 'MIN1000' was removed")
        ->and($eval2['summary']['coupon_discount'])->toBe(0)
        ->and($intent->fresh()->coupon_code)->toBeNull();
});

test('cancelling seller order releases allocated coupon discount back to redemption', function () {
    $catalog = setupCouponCheckoutCatalog();
    $listing = $catalog['listing'];

    $customer = User::factory()->create();
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Suresh Kumar',
        'phone' => '9876543211',
        'pincode' => '560001',
        'locality' => 'Indiranagar',
        'address_line' => '456 Cross Road',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'type' => 'home',
        'is_default' => true,
    ]);

    $coupon = Coupon::create([
        'code' => 'CANCEL50',
        'name' => '₹50 Off',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 5000, // 5,000 paise
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $orderService = app(OrderService::class);

    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [['listing_id' => $listing->id, 'qty' => 1]],
        expectedTotal: 75000, // 80,000 - 5,000 = 75,000
        idempotencyKey: 'intent_cancel_test',
        source: 'cart',
        couponCode: 'CANCEL50',
    );

    $order = $orderService->place($placeData);
    $sellerOrder = $order->sellerOrders()->first();

    $redemption = CouponRedemption::where('order_reference', $order->order_number)->first();
    expect($redemption->status->value)->toBe('redeemed')
        ->and($redemption->released_amount)->toBe(0);

    // Cancel the seller order
    $orderService->cancelSellerOrder(
        sellerOrder: $sellerOrder,
        actorType: 'customer',
        actorId: $customer->id,
        reason: 'Changed my mind'
    );

    // Verify redemption released
    $redemption->refresh();
    expect($redemption->status->value)->toBe('released')
        ->and($redemption->released_amount)->toBe(5000);
});

test('cod order with coupon sets cod collection amount exactly equal to net grand_total', function () {
    $catalog = setupCouponCheckoutCatalog();
    $listing = $catalog['listing'];

    $customer = User::factory()->create();
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Asha Patel',
        'phone' => '9876543212',
        'pincode' => '560001',
        'locality' => 'Koramangala',
        'address_line' => '12 80ft Road',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'type' => 'home',
        'is_default' => true,
    ]);

    $coupon = Coupon::create([
        'code' => 'CODPROMO',
        'name' => '₹100 Off',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 10000, // 10,000 paise (₹100)
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $orderService = app(OrderService::class);

    // Listing is ₹800 (80,000 paise). With ₹100 coupon, net expected total is ₹700 (70,000 paise).
    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [['listing_id' => $listing->id, 'qty' => 1]],
        expectedTotal: 70000,
        idempotencyKey: 'intent_cod_shipping_test',
        source: 'cart',
        couponCode: 'CODPROMO',
    );

    $order = $orderService->place($placeData);
    $sellerOrder = $order->sellerOrders()->first();

    // Verify order and sellerOrder totals
    expect($order->grand_total)->toBe(70000)
        ->and($order->coupon_discount_total)->toBe(10000)
        ->and($sellerOrder->grand_total)->toBe(70000)
        ->and($sellerOrder->coupon_discount_total)->toBe(10000);

    // Invariant check: grand_total = subtotal - coupon_discount_total
    expect($sellerOrder->grand_total)->toBe(
        $sellerOrder->subtotal - $sellerOrder->coupon_discount_total
    );

    // And order grand_total = (items_mrp_total - discount_total) - coupon_discount_total + delivery_fee
    expect($order->grand_total)->toBe(
        ($order->items_mrp_total - $order->discount_total) - $order->coupon_discount_total + $order->delivery_fee
    );

    // Shipping lookup service for carrier driver payload
    $shippingLookup = app(SellerOrderShippingDetailsLookupService::class);
    $shippingDetails = $shippingLookup->getShippingDetails((string) $sellerOrder->id);

    expect($shippingDetails)->not->toBeNull()
        ->and($shippingDetails->isCod)->toBeTrue()
        ->and($shippingDetails->codAmountPaise)->toBe(70000)
        ->and($shippingDetails->codAmountPaise)->toBe($sellerOrder->grand_total);
});

function createSecondSellerAndListing(array $catalog, int $mrp = 50000, int $sellingPrice = 40000): array
{
    $sellerUser2 = User::factory()->create();
    $seller2 = SellerProfile::factory()->create([
        'user_id' => $sellerUser2->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Store 2',
        'gstin' => '29XYZDE1234F1Z6',
        'pickup_state' => 'Karnataka',
    ]);

    $variant2 = ProductVariant::create([
        'product_id' => $catalog['product']->id,
        'variant_key' => 'v2-'.uniqid(),
        'variant_attributes' => [],
        'upid' => 'UPID-'.uniqid(),
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $listing2 = Listing::create([
        'seller_profile_id' => $seller2->id,
        'product_variant_id' => $variant2->id,
        'sku' => 'SKU-V2-'.uniqid(),
        'mrp' => $mrp,
        'selling_price' => $sellingPrice,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    return ['seller' => $seller2, 'variant' => $variant2, 'listing' => $listing2];
}

test('rto does not restore per-user or global usage by default when restore_usage_on_rto is false', function () {
    config(['coupons.restore_usage_on_rto' => false]);

    $catalog = setupCouponCheckoutCatalog();
    $listing = $catalog['listing'];

    $customer = User::factory()->create();
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'RTO Customer',
        'phone' => '9876543213',
        'pincode' => '560001',
        'locality' => 'Whitefield',
        'address_line' => '10 Tech Road',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'type' => 'home',
        'is_default' => true,
    ]);

    $coupon = Coupon::create([
        'code' => 'RTOBLOCK',
        'name' => 'RTO Block',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 5000, // ₹50
        'usage_limit_per_user' => 1,
        'usage_limit_total' => 1,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $orderService = app(OrderService::class);

    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [['listing_id' => $listing->id, 'qty' => 1]],
        expectedTotal: 75000, // 80,000 - 5,000
        idempotencyKey: 'intent_rto_block_test',
        source: 'cart',
        couponCode: 'RTOBLOCK',
    );

    $order = $orderService->place($placeData);
    $sellerOrder = $order->sellerOrders()->first();

    $redemption = CouponRedemption::where('order_reference', $order->order_number)->first();
    expect($redemption->status->value)->toBe('redeemed')
        ->and($coupon->fresh()->usage_count)->toBe(1);

    // Carrier marks shipment as Returned to Origin (RTO)
    $fulfillment = app(SellerOrderFulfillmentInterface::class);
    $fulfillment->markReturnedToOrigin((string) $sellerOrder->id, 'Customer refused delivery');

    $sellerOrder->refresh();
    $redemption->refresh();

    expect($sellerOrder->status->value)->toBe('rto')
        ->and($redemption->status->value)->toBe('redeemed')
        ->and($redemption->released_amount)->toBe(0)
        ->and($coupon->fresh()->usage_count)->toBe(1);

    // Verify per-user usage is NOT restored
    $evaluator = app(CouponEvaluatorInterface::class);
    $testLine = new CartLineContext(
        lineId: $listing->id,
        sellerId: (int) $listing->seller_profile_id,
        productId: (int) $catalog['product']->id,
        categoryIds: [(int) $catalog['vert']->category_id],
        brandId: (int) $catalog['brand']->id,
        unitPrice: 80000,
        quantity: 1,
        lineTotal: 80000
    );

    $userEval = $evaluator->evaluate(
        new CartContext(customerReference: (string) $customer->id, lines: [$testLine]),
        'RTOBLOCK'
    );
    expect($userEval->isValid)->toBeFalse()
        ->and($userEval->errorCode)->toBe('USER_LIMIT_REACHED');

    // Verify global usage is ALSO NOT restored
    $otherCustomer = User::factory()->create();
    $globalEval = $evaluator->evaluate(
        new CartContext(customerReference: (string) $otherCustomer->id, lines: [$testLine]),
        'RTOBLOCK'
    );
    expect($globalEval->isValid)->toBeFalse()
        ->and($globalEval->errorCode)->toBe('GLOBAL_LIMIT_REACHED');
});

test('rto restores per-user and global usage when restore_usage_on_rto is true', function () {
    config(['coupons.restore_usage_on_rto' => true]);

    $catalog = setupCouponCheckoutCatalog();
    $listing = $catalog['listing'];

    $customer = User::factory()->create();
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'RTO Customer 2',
        'phone' => '9876543214',
        'pincode' => '560001',
        'locality' => 'HSR Layout',
        'address_line' => '20 Sector 1',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'type' => 'home',
        'is_default' => true,
    ]);

    $coupon = Coupon::create([
        'code' => 'RTOFREE',
        'name' => 'RTO Free',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 5000,
        'usage_limit_per_user' => 1,
        'usage_limit_total' => 1,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $orderService = app(OrderService::class);

    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [['listing_id' => $listing->id, 'qty' => 1]],
        expectedTotal: 75000,
        idempotencyKey: 'intent_rto_restore_test',
        source: 'cart',
        couponCode: 'RTOFREE',
    );

    $order = $orderService->place($placeData);
    $sellerOrder = $order->sellerOrders()->first();

    $redemption = CouponRedemption::where('order_reference', $order->order_number)->first();
    expect($redemption->status->value)->toBe('redeemed')
        ->and($coupon->fresh()->usage_count)->toBe(1);

    // Carrier marks shipment as Returned to Origin (RTO) with config enabled
    $fulfillment = app(SellerOrderFulfillmentInterface::class);
    $fulfillment->markReturnedToOrigin((string) $sellerOrder->id, 'Delivery attempted but address not reachable');

    $sellerOrder->refresh();
    $redemption->refresh();

    expect($sellerOrder->status->value)->toBe('rto')
        ->and($redemption->status->value)->toBe('released')
        ->and($redemption->released_amount)->toBe(5000)
        ->and($coupon->fresh()->usage_count)->toBe(0);

    // Verify per-user usage IS restored
    $evaluator = app(CouponEvaluatorInterface::class);
    $testLine = new CartLineContext(
        lineId: $listing->id,
        sellerId: (int) $listing->seller_profile_id,
        productId: (int) $catalog['product']->id,
        categoryIds: [(int) $catalog['vert']->category_id],
        brandId: (int) $catalog['brand']->id,
        unitPrice: 80000,
        quantity: 1,
        lineTotal: 80000
    );

    $userEval = $evaluator->evaluate(
        new CartContext(customerReference: (string) $customer->id, lines: [$testLine]),
        'RTOFREE'
    );
    expect($userEval->isValid)->toBeTrue();

    // Verify global usage IS restored
    $otherCustomer = User::factory()->create();
    $globalEval = $evaluator->evaluate(
        new CartContext(customerReference: (string) $otherCustomer->id, lines: [$testLine]),
        'RTOFREE'
    );
    expect($globalEval->isValid)->toBeTrue();
});

test('multi-seller order: cancelling seller A then seller B transitions redemption to partially_released then released', function () {
    $catalog = setupCouponCheckoutCatalog();
    $listingA = $catalog['listing']; // ₹800 selling price = 80,000 paise

    // Seller B listing with ₹200 selling price = 20,000 paise
    // Combined subtotal = 80,000 + 20,000 = 100,000 paise (₹1,000)
    $second = createSecondSellerAndListing($catalog, mrp: 30000, sellingPrice: 20000);
    $listingB = $second['listing'];

    $customer = User::factory()->create();
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Multi Seller Buyer',
        'phone' => '9876543215',
        'pincode' => '560001',
        'locality' => 'Indiranagar',
        'address_line' => '500 100ft Road',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'type' => 'home',
        'is_default' => true,
    ]);

    // Flat ₹200 off (20,000 paise).
    // Proportional allocation:
    // Listing A (80% of 100k) gets 16,000 paise (₹160)
    // Listing B (20% of 100k) gets 4,000 paise (₹40)
    $coupon = Coupon::create([
        'code' => 'MULTI200',
        'name' => 'Multi Seller ₹200 Off',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 20000,
        'usage_limit_per_user' => 1,
        'usage_limit_total' => 1,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $orderService = app(OrderService::class);

    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $listingA->id, 'qty' => 1],
            ['listing_id' => $listingB->id, 'qty' => 1],
        ],
        expectedTotal: 80000, // 100,000 - 20,000 = 80,000
        idempotencyKey: 'intent_multi_seller_cancel_test',
        source: 'cart',
        couponCode: 'MULTI200',
    );

    $order = $orderService->place($placeData);

    $sellerOrders = $order->sellerOrders()->get();
    expect($sellerOrders)->toHaveCount(2);

    $sellerOrderA = $sellerOrders->firstWhere('seller_profile_id', $catalog['seller']->id);
    $sellerOrderB = $sellerOrders->firstWhere('seller_profile_id', $second['seller']->id);

    expect($sellerOrderA->coupon_discount_total)->toBe(16000)
        ->and($sellerOrderB->coupon_discount_total)->toBe(4000);

    $redemption = CouponRedemption::where('order_reference', $order->order_number)->first();
    expect($redemption->status->value)->toBe('redeemed')
        ->and($redemption->discount_amount)->toBe(20000)
        ->and($redemption->released_amount)->toBe(0)
        ->and($coupon->fresh()->usage_count)->toBe(1);

    // Setup dummy cart context to test evaluation
    $evaluator = app(CouponEvaluatorInterface::class);
    $testLine = new CartLineContext(
        lineId: $listingA->id,
        sellerId: (int) $listingA->seller_profile_id,
        productId: (int) $catalog['product']->id,
        categoryIds: [(int) $catalog['vert']->category_id],
        brandId: (int) $catalog['brand']->id,
        unitPrice: 80000,
        quantity: 1,
        lineTotal: 80000
    );

    // Step 1: Cancel Seller A's package
    $orderService->cancelSellerOrder(
        sellerOrder: $sellerOrderA,
        actorType: 'customer',
        actorId: $customer->id,
        reason: 'Seller A package cancelled by customer'
    );

    $redemption->refresh();

    // After Seller A cancelled: partially_released, 16,000 released
    expect($redemption->status->value)->toBe('partially_released')
        ->and($redemption->released_amount)->toBe(16000)
        ->and($coupon->fresh()->usage_count)->toBe(1);

    // Per-user and global usage are STILL consumed
    $evalUser1 = $evaluator->evaluate(
        new CartContext(customerReference: (string) $customer->id, lines: [$testLine]),
        'MULTI200'
    );
    expect($evalUser1->isValid)->toBeFalse()
        ->and($evalUser1->errorCode)->toBe('USER_LIMIT_REACHED');

    $otherUser = User::factory()->create();
    $evalGlobal1 = $evaluator->evaluate(
        new CartContext(customerReference: (string) $otherUser->id, lines: [$testLine]),
        'MULTI200'
    );
    expect($evalGlobal1->isValid)->toBeFalse()
        ->and($evalGlobal1->errorCode)->toBe('GLOBAL_LIMIT_REACHED');

    // Step 2: Cancel Seller B's package
    $orderService->cancelSellerOrder(
        sellerOrder: $sellerOrderB,
        actorType: 'customer',
        actorId: $customer->id,
        reason: 'Seller B package cancelled by customer'
    );

    $redemption->refresh();

    // After Seller B cancelled: fully released, exactly 20,000 released
    expect($redemption->status->value)->toBe('released')
        ->and($redemption->released_amount)->toBe(20000)
        ->and($redemption->released_amount)->toBe($redemption->discount_amount) // Sums exactly
        ->and($coupon->fresh()->usage_count)->toBe(0); // Usage count restored

    // Per-user usage is now RESTORED
    $evalUser2 = $evaluator->evaluate(
        new CartContext(customerReference: (string) $customer->id, lines: [$testLine]),
        'MULTI200'
    );
    expect($evalUser2->isValid)->toBeTrue();

    // Global usage is now RESTORED
    $evalGlobal2 = $evaluator->evaluate(
        new CartContext(customerReference: (string) $otherUser->id, lines: [$testLine]),
        'MULTI200'
    );
    expect($evalGlobal2->isValid)->toBeTrue();
});
