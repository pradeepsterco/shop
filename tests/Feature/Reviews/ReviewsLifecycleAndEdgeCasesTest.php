<?php

use App\Enums\BrandStatus;
use App\Enums\ProductQcStatus;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
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
use App\Reviews\DTOs\ReviewSubmissionData;
use App\Reviews\Enums\ReviewStatus;
use App\Reviews\Models\ProductReview;
use App\Reviews\Models\ReviewSummary;
use App\Reviews\Services\ProductReviewService;
use App\Reviews\Services\ReviewSummaryService;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Artisan::call('permissions:sync');

    $this->category = Category::create([
        'name' => 'Edge Category',
        'slug' => 'edge-cat-'.uniqid(),
    ]);

    $this->vertical = Vertical::create([
        'category_id' => $this->category->id,
        'name' => 'Edge Vertical',
        'slug' => 'edge-vert-'.uniqid(),
        'hsn_code' => '8518',
        'gst_rate' => 18.0,
    ]);

    $this->brand = Brand::create([
        'name' => 'Edge Brand',
        'slug' => 'edge-brand-'.uniqid(),
        'status' => BrandStatus::Approved,
    ]);

    $this->product = Product::create([
        'vertical_id' => $this->vertical->id,
        'brand_id' => $this->brand->id,
        'title' => 'Edge Test Ergonomic Chair',
        'slug' => 'edge-chair-'.uniqid(),
        'description' => 'Tested chair for reviews lifecycle',
        'qc_status' => ProductQcStatus::Approved,
        'in_stock' => true,
    ]);
});

function createEdgeDeliveredContext(int $productId, ?User $customer = null, ?string $toStatus = SellerOrderStatus::Delivered->value): array
{
    $customer ??= User::factory()->create();
    $sellerUser = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $sellerUser->id]);

    $variantId = DB::table('product_variants')->insertGetId([
        'product_id' => $productId,
        'upid' => 'UPID-'.uniqid(),
        'variant_key' => 'vk-'.uniqid(),
        'variant_attributes' => json_encode(['color' => 'Black']),
        'qc_status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $order = Order::create([
        'order_number' => 'ORD-'.strtoupper(Str::random(8)),
        'user_id' => $customer->id,
        'checkout_intent_id' => (string) Str::uuid(),
        'status' => OrderStatus::Completed,
        'delivery_address' => ['city' => 'Mumbai', 'state' => 'Maharashtra'],
        'delivery_state' => 'Maharashtra',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Completed,
        'items_mrp_total' => 12000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 12000,
        'placed_at' => now(),
    ]);

    $sellerOrder = SellerOrder::create([
        'order_id' => $order->id,
        'seller_profile_id' => $seller->id,
        'seller_store_name' => 'Edge Store',
        'status' => $toStatus === SellerOrderStatus::Delivered->value ? SellerOrderStatus::Delivered : SellerOrderStatus::Shipped,
        'subtotal' => 12000,
        'discount_total' => 0,
        'grand_total' => 12000,
        'placed_at' => now(),
        'delivered_at' => $toStatus === SellerOrderStatus::Delivered->value ? now()->subDays(2) : null,
    ]);

    $orderItem = OrderItem::create([
        'seller_order_id' => $sellerOrder->id,
        'product_variant_id' => $variantId,
        'title' => 'Edge Test Ergonomic Chair',
        'variant_title' => 'Black',
        'sku' => 'SKU-'.uniqid(),
        'upid' => 'UPID-'.uniqid(),
        'seller_name' => 'Edge Store',
        'qty' => 1,
        'mrp' => 12000,
        'unit_price' => 12000,
        'line_total' => 12000,
        'gst_rate' => 18.0,
        'gst_amount' => 2160,
    ]);

    if ($toStatus !== null) {
        OrderStatusHistory::create([
            'seller_order_id' => $sellerOrder->id,
            'from_status' => 'shipped',
            'to_status' => $toStatus,
            'changed_by_type' => 'carrier',
            'created_at' => now()->subDays(2),
        ]);
    }

    return compact('customer', 'order', 'sellerOrder', 'orderItem', 'variantId', 'seller');
}

test('customer account deletion and anonymization preserve historical review, author snapshot, and rating aggregate', function () {
    $ctx = createEdgeDeliveredContext($this->product->id);
    $customer = $ctx['customer'];
    $customerName = $customer->name;

    $service = app(ProductReviewService::class);
    $review = $service->submitOrUpdate(
        $customer,
        $this->product->id,
        new ReviewSubmissionData(rating: 5, comment: 'Super comfortable for working from home.', title: 'Top Chair')
    );

    $summaryBefore = ReviewSummary::where('reviewable_id', $this->product->id)->first();
    expect($summaryBefore->rating_count)->toBe(1)
        ->and($summaryBefore->rating_avg)->toBe(5.0);

    // 1. Test standard application anonymization workflow for customers with historical orders
    $customer->anonymize();
    expect($customer->isAnonymized())->toBeTrue()
        ->and($customer->name)->toBe('Deleted user');

    $review->refresh();
    // Review retains the author name snapshot from when it was written
    expect($review->author_name)->toBe($customerName)
        ->and($review->rating)->toBe(5)
        ->and($review->is_verified_purchase)->toBeTrue();

    // 2. Test raw database user deletion when orders are purged
    // (orders table has restrictOnDelete; deleting orders allows user deletion, triggering nullOnDelete on product_reviews)
    DB::table('order_status_histories')->where('seller_order_id', $ctx['sellerOrder']->id)->delete();
    DB::table('order_items')->where('seller_order_id', $ctx['sellerOrder']->id)->delete();
    DB::table('seller_orders')->where('order_id', $ctx['order']->id)->delete();
    DB::table('orders')->where('id', $ctx['order']->id)->delete();

    $customer->delete();

    $review->refresh();
    expect($review->user_id)->toBeNull()
        ->and($review->author_name)->toBe($customerName)
        ->and($review->is_verified_purchase)->toBeTrue();

    // Summary is unaffected
    $summaryAfter = ReviewSummary::where('reviewable_id', $this->product->id)->first();
    expect($summaryAfter->rating_count)->toBe(1)
        ->and($summaryAfter->rating_avg)->toBe(5.0);

    // Verify storefront component renders without crashing
    Livewire::test('product-reviews-section', ['productId' => $this->product->id])
        ->assertSee($customerName)
        ->assertSee('Top Chair')
        ->assertSee('Verified Purchase');

    // Verify admin moderation index renders with "No account" snapshot and allows moderation
    $superAdmin = Admin::factory()->create(['is_active' => true]);
    $superAdmin->syncRoles([Role::findOrCreate('Super Admin', 'admin')]);

    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.reviews-index')
        ->assertSee($customerName)
        ->assertSee('No account')
        ->call('hideReview', $review->id)
        ->assertHasNoErrors();

    $review->refresh();
    expect($review->status)->toBe(ReviewStatus::Hidden);

    $summaryHidden = ReviewSummary::where('reviewable_id', $this->product->id)->first();
    expect($summaryHidden->rating_count)->toBe(0);
});

test('inactive or out of stock products retain their review history and rating summaries', function () {
    $ctx = createEdgeDeliveredContext($this->product->id);
    $service = app(ProductReviewService::class);
    $service->submitOrUpdate(
        $ctx['customer'],
        $this->product->id,
        new ReviewSubmissionData(rating: 4, comment: 'High build quality, solid back support.')
    );

    // Deactivate and mark out of stock
    $this->product->update([
        'in_stock' => false,
        'qc_status' => ProductQcStatus::Rejected,
    ]);

    $summary = $service->getSummary($this->product->id);
    expect($summary)->not->toBeNull()
        ->and($summary->rating_count)->toBe(1)
        ->and($summary->rating_avg)->toBe(4.0);

    $review = ProductReview::where('reviewable_id', $this->product->id)->first();
    expect($review)->not->toBeNull()
        ->and($review->rating)->toBe(4);
});

test('subsequent return or refund does not invalidate verified purchase eligibility for genuinely delivered purchase', function () {
    $ctx = createEdgeDeliveredContext($this->product->id);
    $customer = $ctx['customer'];
    $sellerOrder = $ctx['sellerOrder'];

    // Genuine delivery already recorded in history. Later order was returned and refunded:
    $sellerOrder->update(['status' => SellerOrderStatus::Refunded]);
    OrderStatusHistory::create([
        'seller_order_id' => $sellerOrder->id,
        'from_status' => 'delivered',
        'to_status' => 'refunded',
        'changed_by_type' => 'customer',
        'created_at' => now(),
    ]);

    $adapter = app(OrderDeliveredReviewEligibilityAdapter::class);
    $result = $adapter->check($customer->id, $this->product->id);

    expect($result->canReview)->toBeTrue()
        ->and($result->orderItemId)->toBe((string) $ctx['orderItem']->id);
});

test('cancelled, RTO, lost, and undelivered orders do not qualify merely because a refund exists', function () {
    $customer = User::factory()->create();
    $sellerUser = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $sellerUser->id]);

    $variantId = DB::table('product_variants')->insertGetId([
        'product_id' => $this->product->id,
        'upid' => 'UPID-'.uniqid(),
        'variant_key' => 'vk-'.uniqid(),
        'variant_attributes' => json_encode(['color' => 'Red']),
        'qc_status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $order = Order::create([
        'order_number' => 'ORD-'.strtoupper(Str::random(8)),
        'user_id' => $customer->id,
        'checkout_intent_id' => (string) Str::uuid(),
        'status' => OrderStatus::Cancelled,
        'delivery_address' => ['city' => 'Delhi', 'state' => 'Delhi'],
        'delivery_state' => 'Delhi',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Cancelled,
        'items_mrp_total' => 5000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 5000,
        'placed_at' => now(),
    ]);

    $sellerOrder = SellerOrder::create([
        'order_id' => $order->id,
        'seller_profile_id' => $seller->id,
        'seller_store_name' => 'Edge Store',
        'status' => SellerOrderStatus::Cancelled,
        'subtotal' => 5000,
        'discount_total' => 0,
        'grand_total' => 5000,
        'placed_at' => now(),
        'delivered_at' => null, // Never delivered!
    ]);

    OrderItem::create([
        'seller_order_id' => $sellerOrder->id,
        'product_variant_id' => $variantId,
        'title' => 'Edge Test Ergonomic Chair',
        'variant_title' => 'Red',
        'sku' => 'SKU-'.uniqid(),
        'upid' => 'UPID-'.uniqid(),
        'seller_name' => 'Edge Store',
        'qty' => 1,
        'mrp' => 5000,
        'unit_price' => 5000,
        'line_total' => 5000,
        'gst_rate' => 18.0,
        'gst_amount' => 900,
    ]);

    OrderStatusHistory::create([
        'seller_order_id' => $sellerOrder->id,
        'from_status' => 'placed',
        'to_status' => 'cancelled',
        'changed_by_type' => 'customer',
    ]);

    $adapter = app(OrderDeliveredReviewEligibilityAdapter::class);
    $result = $adapter->check($customer->id, $this->product->id);

    expect($result->canReview)->toBeFalse()
        ->and($result->reason)->toContain('Only verified buyers');
});

test('historical delivered orders completed before review system launched qualify', function () {
    $customer = User::factory()->create();
    $sellerUser = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $sellerUser->id]);

    $variantId = DB::table('product_variants')->insertGetId([
        'product_id' => $this->product->id,
        'upid' => 'UPID-'.uniqid(),
        'variant_key' => 'vk-'.uniqid(),
        'variant_attributes' => json_encode(['color' => 'Blue']),
        'qc_status' => 'approved',
        'created_at' => now()->subYears(2),
        'updated_at' => now()->subYears(2),
    ]);

    $historicalDeliveredAt = now()->subYear();

    $order = Order::create([
        'order_number' => 'ORD-'.strtoupper(Str::random(8)),
        'user_id' => $customer->id,
        'checkout_intent_id' => (string) Str::uuid(),
        'status' => OrderStatus::Placed,
        'delivery_address' => ['city' => 'Bengaluru', 'state' => 'Karnataka'],
        'delivery_state' => 'Karnataka',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Completed,
        'items_mrp_total' => 8000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 8000,
        'placed_at' => $historicalDeliveredAt->copy()->subDays(5),
        'created_at' => $historicalDeliveredAt->copy()->subDays(5),
    ]);

    $sellerOrder = SellerOrder::create([
        'order_id' => $order->id,
        'seller_profile_id' => $seller->id,
        'seller_store_name' => 'Edge Store',
        'status' => SellerOrderStatus::Delivered,
        'subtotal' => 8000,
        'discount_total' => 0,
        'grand_total' => 8000,
        'placed_at' => $historicalDeliveredAt->copy()->subDays(5),
        'delivered_at' => $historicalDeliveredAt,
        'created_at' => $historicalDeliveredAt->copy()->subDays(5),
    ]);

    $orderItem = OrderItem::create([
        'seller_order_id' => $sellerOrder->id,
        'product_variant_id' => $variantId,
        'title' => 'Edge Test Ergonomic Chair',
        'variant_title' => 'Blue',
        'sku' => 'SKU-'.uniqid(),
        'upid' => 'UPID-'.uniqid(),
        'seller_name' => 'Edge Store',
        'qty' => 1,
        'mrp' => 8000,
        'unit_price' => 8000,
        'line_total' => 8000,
        'gst_rate' => 18.0,
        'gst_amount' => 1440,
        'created_at' => $historicalDeliveredAt->copy()->subDays(5),
    ]);

    OrderStatusHistory::create([
        'seller_order_id' => $sellerOrder->id,
        'from_status' => 'shipped',
        'to_status' => 'delivered',
        'changed_by_type' => 'carrier',
        'created_at' => $historicalDeliveredAt,
    ]);

    $adapter = app(OrderDeliveredReviewEligibilityAdapter::class);
    $result = $adapter->check($customer->id, $this->product->id);

    expect($result->canReview)->toBeTrue()
        ->and($result->orderItemId)->toBe((string) $orderItem->id);

    $service = app(ProductReviewService::class);
    $review = $service->submitOrUpdate(
        $customer,
        $this->product->id,
        new ReviewSubmissionData(rating: 5, comment: 'Purchased over a year ago, still as sturdy as day one!')
    );

    expect($review->is_verified_purchase)->toBeTrue()
        ->and($review->order_item_id)->toBe((string) $orderItem->id);
});

test('an order item cannot be claimed to create a second review', function () {
    $ctx = createEdgeDeliveredContext($this->product->id);
    $customer = $ctx['customer'];
    $orderItem = $ctx['orderItem'];

    $service = app(ProductReviewService::class);
    $service->submitOrUpdate(
        $customer,
        $this->product->id,
        new ReviewSubmissionData(rating: 5, comment: 'Initial review for item.')
    );

    $adapter = app(OrderDeliveredReviewEligibilityAdapter::class);

    // Customer checks again:
    $result = $adapter->check($customer->id, $this->product->id);
    // They are eligible to EDIT their existing review, not create another:
    expect($result->hasExistingReview)->toBeTrue();

    // Another customer checks eligibility on this product without an unclaimed delivered item:
    $otherCustomer = User::factory()->create();
    $otherResult = $adapter->check($otherCustomer->id, $this->product->id);
    expect($otherResult->canReview)->toBeFalse();
});

test('unauthorized direct Livewire calls are rejected', function () {
    // 1. Unauthenticated guest calling saveReview on product reviews component
    Livewire::test('product-reviews-section', ['productId' => $this->product->id])
        ->set('rating', 5)
        ->set('comment', 'Attempting bypass as guest.')
        ->call('saveReview')
        ->assertRedirect(route('login'));

    // 2. Authenticated user without delivery calling saveReview gets domain exception feedback
    $ineligibleCustomer = User::factory()->create();
    Livewire::actingAs($ineligibleCustomer)
        ->test('product-reviews-section', ['productId' => $this->product->id])
        ->set('rating', 5)
        ->set('comment', 'Attempting bypass without delivery.')
        ->call('saveReview')
        ->assertSet('showForm', false)
        ->assertSee('Only verified buyers who have received this product can write a review.');

    // 3. Unauthenticated guest accessing admin reviews component
    Livewire::test('pages::admin.reviews-index')
        ->assertForbidden();

    // 4. Staff without reviews.manage calling moderation actions
    $viewerAdmin = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('Review Viewer Only', 'admin');
    $role->syncPermissions([AdminPermissions::REVIEWS_VIEW]);
    $viewerAdmin->syncRoles([$role]);

    $review = ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $this->product->id,
        'author_name' => 'Test Subject',
        'rating' => 4,
        'comment' => 'Sample test comment.',
        'status' => ReviewStatus::Approved,
        'is_verified_purchase' => true,
    ]);

    Livewire::actingAs($viewerAdmin, 'admin')
        ->test('pages::admin.reviews-index')
        ->call('hideReview', $review->id)
        ->assertForbidden();

    Livewire::actingAs($viewerAdmin, 'admin')
        ->test('pages::admin.reviews-index')
        ->call('rejectReview', $review->id)
        ->assertForbidden();

    Livewire::actingAs($viewerAdmin, 'admin')
        ->test('pages::admin.reviews-index')
        ->call('restoreReview', $review->id)
        ->assertForbidden();

    Livewire::actingAs($viewerAdmin, 'admin')
        ->test('pages::admin.reviews-index')
        ->call('confirmDelete', $review->id)
        ->assertForbidden();
});

test('batched summary lookup prevents N+1 queries across catalog products', function () {
    $products = [];
    for ($i = 0; $i < 10; $i++) {
        $p = Product::create([
            'vertical_id' => $this->vertical->id,
            'brand_id' => $this->brand->id,
            'title' => "Batch Product {$i}",
            'slug' => "batch-product-{$i}-".uniqid(),
            'description' => "Desc {$i}",
        ]);
        $products[] = $p;

        ReviewSummary::create([
            'reviewable_type' => 'product',
            'reviewable_id' => $p->id,
            'rating_avg' => 4.5,
            'rating_count' => 10,
            'star_1' => 0,
            'star_2' => 0,
            'star_3' => 1,
            'star_4' => 3,
            'star_5' => 6,
        ]);
    }

    $productIds = collect($products)->pluck('id')->all();

    DB::enableQueryLog();
    $summaries = app(ReviewSummaryService::class)->getSummariesForProducts($productIds);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Exactly 1 SQL query must be executed to fetch all 10 summaries
    expect($queries)->toHaveCount(1)
        ->and($summaries)->toHaveCount(10);
});
