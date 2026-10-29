<?php

use App\Enums\BrandStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Orders\Adapters\OrderDeliveredReviewEligibilityAdapter;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\OrderStatusHistory;
use App\Orders\Models\SellerOrder;
use App\Reviews\Contracts\ReviewEligibilityInterface;
use App\Reviews\DTOs\ReviewSubmissionData;
use App\Reviews\Enums\ReviewStatus;
use App\Reviews\Models\ProductReview;
use App\Reviews\Services\ProductReviewService;
use App\Services\UpidGenerator;
use Carbon\Carbon;
use Illuminate\Support\Str;

function createReviewTestFixture(): array
{
    $customer = User::factory()->create(['name' => 'Alice Customer']);
    $sellerUser = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $sellerUser->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Acme Store',
    ]);

    $brand = Brand::create(['name' => 'AcmeBrand', 'slug' => 'acme-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Electronics', 'slug' => 'elec-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Headphones', 'slug' => 'hp-'.uniqid(), 'hsn_code' => '8518', 'gst_rate' => 18.0]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Wireless Headphones',
        'slug' => 'wireless-headphones-'.uniqid(),
        'description' => 'High quality wireless headphones',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'Black'],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    return compact('customer', 'seller', 'product', 'variant');
}

function createOrderWithItem(
    User $customer,
    SellerProfile $seller,
    ProductVariant $variant,
    SellerOrderStatus $sellerStatus,
    ?string $deliveredAt = null,
    ?string $historyStatus = null,
): array {
    $order = Order::create([
        'order_number' => 'ORD-'.strtoupper(Str::random(8)),
        'user_id' => $customer->id,
        'checkout_intent_id' => (string) Str::uuid(),
        'status' => OrderStatus::Placed,
        'delivery_address' => ['city' => 'Mumbai', 'state' => 'Maharashtra'],
        'delivery_state' => 'Maharashtra',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Completed,
        'items_mrp_total' => 200000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 200000,
        'placed_at' => now(),
    ]);

    $sellerOrder = SellerOrder::create([
        'order_id' => $order->id,
        'seller_profile_id' => $seller->id,
        'seller_store_name' => $seller->store_name,
        'status' => $sellerStatus,
        'subtotal' => 200000,
        'discount_total' => 0,
        'coupon_discount_total' => 0,
        'grand_total' => 200000,
        'placed_at' => now(),
        'delivered_at' => $deliveredAt,
    ]);

    $orderItem = OrderItem::create([
        'seller_order_id' => $sellerOrder->id,
        'product_variant_id' => $variant->id,
        'title' => 'Wireless Headphones',
        'variant_title' => 'Black',
        'sku' => 'SKU-'.uniqid(),
        'upid' => $variant->upid,
        'seller_name' => $seller->store_name,
        'unit_price' => 200000,
        'mrp' => 200000,
        'qty' => 1,
        'line_total' => 200000,
        'gst_rate' => 18.0,
        'gst_amount' => 36000,
    ]);

    if ($historyStatus !== null) {
        OrderStatusHistory::create([
            'seller_order_id' => $sellerOrder->id,
            'from_status' => 'shipped',
            'to_status' => $historyStatus,
            'changed_by_type' => 'carrier',
            'created_at' => $deliveredAt ? Carbon::parse($deliveredAt) : now(),
        ]);
    }

    return compact('order', 'sellerOrder', 'orderItem');
}

test('adapter verifies eligibility for fully delivered purchases', function () {
    extract(createReviewTestFixture());
    $deliveredAt = now()->toDateTimeString();

    $data = createOrderWithItem(
        $customer,
        $seller,
        $variant,
        SellerOrderStatus::Delivered,
        $deliveredAt,
        'delivered'
    );

    $adapter = new OrderDeliveredReviewEligibilityAdapter;
    $result = $adapter->check($customer->id, $product->id);

    expect($result->canReview)->toBeTrue();
    expect($result->hasExistingReview)->toBeFalse();
    expect($result->orderItemId)->toBe((string) $data['orderItem']->id);
    expect($result->variantId)->toBe($variant->id);
    expect($result->sellerId)->toBe($seller->id);
});

test('adapter verifies eligibility for partially delivered purchases', function () {
    extract(createReviewTestFixture());
    $deliveredAt = now()->toDateTimeString();

    $data = createOrderWithItem(
        $customer,
        $seller,
        $variant,
        SellerOrderStatus::PartiallyDelivered,
        $deliveredAt,
        'partially_delivered'
    );

    $adapter = new OrderDeliveredReviewEligibilityAdapter;
    $result = $adapter->check($customer->id, $product->id);

    expect($result->canReview)->toBeTrue();
    expect($result->hasExistingReview)->toBeFalse();
    expect($result->orderItemId)->toBe((string) $data['orderItem']->id);
});

test('adapter supports historical delivered purchases from months ago', function () {
    extract(createReviewTestFixture());
    $deliveredAt = now()->subMonths(6)->toDateTimeString();

    $data = createOrderWithItem(
        $customer,
        $seller,
        $variant,
        SellerOrderStatus::Delivered,
        $deliveredAt,
        'delivered'
    );

    $adapter = new OrderDeliveredReviewEligibilityAdapter;
    $result = $adapter->check($customer->id, $product->id);

    expect($result->canReview)->toBeTrue();
    expect($result->orderItemId)->toBe((string) $data['orderItem']->id);
});

test('adapter preserves eligibility when delivered order was subsequently refunded or returned', function () {
    extract(createReviewTestFixture());
    $deliveredAt = now()->subDays(5)->toDateTimeString();

    // Delivered previously, then transitioned to refunded
    $data = createOrderWithItem(
        $customer,
        $seller,
        $variant,
        SellerOrderStatus::Refunded,
        $deliveredAt,
        'delivered'
    );

    // Additional status history for refund
    OrderStatusHistory::create([
        'seller_order_id' => $data['sellerOrder']->id,
        'from_status' => 'delivered',
        'to_status' => 'refunded',
        'changed_by_type' => 'admin',
        'created_at' => now(),
    ]);

    $adapter = new OrderDeliveredReviewEligibilityAdapter;
    $result = $adapter->check($customer->id, $product->id);

    // Retains eligibility because genuine delivery happened
    expect($result->canReview)->toBeTrue();
    expect($result->orderItemId)->toBe((string) $data['orderItem']->id);
});

test('adapter rejects cancelled orders even if refunded', function () {
    extract(createReviewTestFixture());

    createOrderWithItem(
        $customer,
        $seller,
        $variant,
        SellerOrderStatus::Cancelled,
        null,
        'cancelled'
    );

    $adapter = new OrderDeliveredReviewEligibilityAdapter;
    $result = $adapter->check($customer->id, $product->id);

    expect($result->canReview)->toBeFalse();
    expect($result->reason)->toContain('Only verified buyers');
});

test('adapter rejects returned-to-origin (RTO) orders', function () {
    extract(createReviewTestFixture());

    createOrderWithItem(
        $customer,
        $seller,
        $variant,
        SellerOrderStatus::ReturnedToOrigin,
        null,
        'rto'
    );

    $adapter = new OrderDeliveredReviewEligibilityAdapter;
    $result = $adapter->check($customer->id, $product->id);

    expect($result->canReview)->toBeFalse();
    expect($result->reason)->toContain('Only verified buyers');
});

test('adapter rejects lost-in-transit orders even when a refund exists', function () {
    extract(createReviewTestFixture());

    createOrderWithItem(
        $customer,
        $seller,
        $variant,
        SellerOrderStatus::LostInTransit,
        null,
        'lost'
    );

    $adapter = new OrderDeliveredReviewEligibilityAdapter;
    $result = $adapter->check($customer->id, $product->id);

    expect($result->canReview)->toBeFalse();
    expect($result->reason)->toContain('Only verified buyers');
});

test('adapter rejects customers who do not own the delivered order', function () {
    extract(createReviewTestFixture());
    $otherCustomer = User::factory()->create();

    createOrderWithItem(
        $customer,
        $seller,
        $variant,
        SellerOrderStatus::Delivered,
        now()->toDateTimeString(),
        'delivered'
    );

    $adapter = new OrderDeliveredReviewEligibilityAdapter;
    $result = $adapter->check($otherCustomer->id, $product->id);

    expect($result->canReview)->toBeFalse();
    expect($result->reason)->toContain('Only verified buyers');
});

test('adapter detects existing review and allows customer to edit it', function () {
    extract(createReviewTestFixture());

    $data = createOrderWithItem(
        $customer,
        $seller,
        $variant,
        SellerOrderStatus::Delivered,
        now()->toDateTimeString(),
        'delivered'
    );

    $review = ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $product->id,
        'user_id' => $customer->id,
        'author_name' => $customer->name,
        'rating' => 4,
        'comment' => 'Existing review comment',
        'is_verified_purchase' => true,
        'status' => ReviewStatus::Approved,
        'order_item_id' => (string) $data['orderItem']->id,
        'product_variant_id' => $variant->id,
        'seller_id' => $seller->id,
    ]);

    $adapter = new OrderDeliveredReviewEligibilityAdapter;
    $result = $adapter->check($customer->id, $product->id);

    expect($result->canReview)->toBeTrue();
    expect($result->hasExistingReview)->toBeTrue();
    expect($result->existingReviewId)->toBe($review->id);
    expect($result->orderItemId)->toBe((string) $data['orderItem']->id);
});

test('adapter prevents an already claimed purchase item from being reused when customer has no review', function () {
    extract(createReviewTestFixture());

    $data = createOrderWithItem(
        $customer,
        $seller,
        $variant,
        SellerOrderStatus::Delivered,
        now()->toDateTimeString(),
        'delivered'
    );

    // Simulate item already claimed by an existing review
    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $product->id,
        'user_id' => null, // orphaned or another author
        'author_name' => 'Previous Author',
        'rating' => 5,
        'comment' => 'Claimed item review',
        'is_verified_purchase' => true,
        'status' => ReviewStatus::Approved,
        'order_item_id' => (string) $data['orderItem']->id,
    ]);

    $adapter = new OrderDeliveredReviewEligibilityAdapter;
    $result = $adapter->check($customer->id, $product->id);

    expect($result->canReview)->toBeFalse();
    expect($result->reason)->toBe('The purchased item(s) for this product have already been reviewed.');
});

test('container resolves ReviewEligibilityInterface to OrderDeliveredReviewEligibilityAdapter', function () {
    $resolved = app(ReviewEligibilityInterface::class);

    expect($resolved)->toBeInstanceOf(OrderDeliveredReviewEligibilityAdapter::class);
});

test('end to end: ProductReviewService uses adapter to submit and update verified purchase review', function () {
    extract(createReviewTestFixture());

    $data = createOrderWithItem(
        $customer,
        $seller,
        $variant,
        SellerOrderStatus::Delivered,
        now()->toDateTimeString(),
        'delivered'
    );

    /** @var ProductReviewService $service */
    $service = app(ProductReviewService::class);

    // Initial review submission
    $review = $service->submitOrUpdate($customer, $product->id, new ReviewSubmissionData(
        rating: 5,
        comment: 'Great product, genuinely delivered!',
        title: 'Superb quality'
    ));

    expect($review->is_verified_purchase)->toBeTrue();
    expect($review->order_item_id)->toBe((string) $data['orderItem']->id);
    expect($review->product_variant_id)->toBe($variant->id);
    expect($review->seller_id)->toBe($seller->id);

    // Re-check eligibility: now has existing review
    $eligibility = $service->checkEligibility($customer, $product->id);
    expect($eligibility->hasExistingReview)->toBeTrue();
    expect($eligibility->existingReviewId)->toBe($review->id);

    // Customer edits review
    $updated = $service->submitOrUpdate($customer, $product->id, new ReviewSubmissionData(
        rating: 4,
        comment: 'Updated review: Still great after a few weeks.',
        title: 'Long-term update'
    ));

    expect($updated->id)->toBe($review->id);
    expect($updated->rating)->toBe(4);
    expect($updated->order_item_id)->toBe((string) $data['orderItem']->id);
    expect(ProductReview::where('reviewable_id', $product->id)->count())->toBe(1);
});
