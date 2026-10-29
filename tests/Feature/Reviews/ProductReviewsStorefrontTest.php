<?php

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
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\OrderStatusHistory;
use App\Orders\Models\SellerOrder;
use App\Reviews\Enums\ReviewStatus;
use App\Reviews\Models\ProductReview;
use App\Reviews\Services\ProductReviewService;
use App\Reviews\Services\ReviewSummaryService;
use App\Services\UpidGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

function createStorefrontReviewContext(): array
{
    $customer = User::factory()->create(['name' => 'John Customer']);
    $sellerUser = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $sellerUser->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Audio Store',
    ]);

    $brand = Brand::create(['name' => 'AudioBrand', 'slug' => 'audio-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Audio', 'slug' => 'audio-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Earphones', 'slug' => 'earphones-'.uniqid(), 'hsn_code' => '8518', 'gst_rate' => 18.0]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Noise Cancelling Earbuds',
        'slug' => 'earbuds-'.uniqid(),
        'description' => 'Premium noise cancelling wireless earbuds',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'White'],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $listing = Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SKU-'.uniqid(),
        'mrp' => 150000,
        'selling_price' => 120000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    $product->update([
        'buy_box_listing_id' => $listing->id,
        'buy_box_mrp' => 150000,
        'min_price' => 120000,
        'in_stock' => true,
    ]);

    ProductImage::create([
        'product_id' => $product->id,
        'path' => 'products/earbuds.jpg',
        'is_primary' => true,
        'status' => ProductImageStatus::Live,
        'sort_order' => 1,
    ]);

    return compact('customer', 'seller', 'product', 'variant', 'listing');
}

function deliverOrderForStorefront(User $customer, SellerProfile $seller, ProductVariant $variant): OrderItem
{
    $order = Order::create([
        'order_number' => 'ORD-'.strtoupper(Str::random(8)),
        'user_id' => $customer->id,
        'checkout_intent_id' => (string) Str::uuid(),
        'status' => OrderStatus::Completed,
        'delivery_address' => ['city' => 'Delhi', 'state' => 'Delhi'],
        'delivery_state' => 'Delhi',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Completed,
        'items_mrp_total' => 150000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 150000,
        'placed_at' => now(),
    ]);

    $sellerOrder = SellerOrder::create([
        'order_id' => $order->id,
        'seller_profile_id' => $seller->id,
        'seller_store_name' => $seller->store_name,
        'status' => SellerOrderStatus::Delivered,
        'subtotal' => 150000,
        'discount_total' => 0,
        'coupon_discount_total' => 0,
        'grand_total' => 150000,
        'placed_at' => now(),
        'delivered_at' => now(),
    ]);

    $orderItem = OrderItem::create([
        'seller_order_id' => $sellerOrder->id,
        'product_variant_id' => $variant->id,
        'title' => 'Noise Cancelling Earbuds',
        'variant_title' => 'White',
        'sku' => 'SKU-'.uniqid(),
        'upid' => $variant->upid,
        'seller_name' => $seller->store_name,
        'unit_price' => 150000,
        'mrp' => 150000,
        'qty' => 1,
        'line_total' => 150000,
        'gst_rate' => 18.0,
        'gst_amount' => 27000,
    ]);

    OrderStatusHistory::create([
        'seller_order_id' => $sellerOrder->id,
        'from_status' => 'shipped',
        'to_status' => 'delivered',
        'changed_by_type' => 'carrier',
        'created_at' => now(),
    ]);

    return $orderItem;
}

test('public visibility: approved reviews are shown while hidden and rejected reviews are excluded', function () {
    extract(createStorefrontReviewContext());

    $u1 = User::factory()->create(['name' => 'Approved Reviewer']);
    $u2 = User::factory()->create(['name' => 'Hidden Reviewer']);
    $u3 = User::factory()->create(['name' => 'Rejected Reviewer']);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $product->id,
        'user_id' => $u1->id,
        'author_name' => 'Approved Reviewer',
        'rating' => 5,
        'title' => 'Visible Title',
        'comment' => 'This is an approved visible comment.',
        'status' => ReviewStatus::Approved,
        'is_verified_purchase' => true,
    ]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $product->id,
        'user_id' => $u2->id,
        'author_name' => 'Hidden Reviewer',
        'rating' => 1,
        'title' => 'Hidden Title',
        'comment' => 'This comment is hidden and must not appear.',
        'status' => ReviewStatus::Hidden,
        'is_verified_purchase' => true,
    ]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $product->id,
        'user_id' => $u3->id,
        'author_name' => 'Rejected Reviewer',
        'rating' => 1,
        'title' => 'Rejected Title',
        'comment' => 'This comment is rejected and must not appear.',
        'status' => ReviewStatus::Rejected,
        'is_verified_purchase' => true,
    ]);

    // Recalculate summary
    app(ProductReviewService::class)->getSummary($product->id);

    Livewire::test('product-reviews-section', ['productId' => $product->id])
        ->assertSee('Approved Reviewer')
        ->assertSee('Visible Title')
        ->assertSee('This is an approved visible comment.')
        ->assertDontSee('Hidden Reviewer')
        ->assertDontSee('This comment is hidden and must not appear.')
        ->assertDontSee('Rejected Reviewer')
        ->assertDontSee('This comment is rejected and must not appear.');
});

test('rating summary displays accurate average and star breakdown for approved reviews', function () {
    extract(createStorefrontReviewContext());

    $u1 = User::factory()->create();
    $u2 = User::factory()->create();

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $product->id,
        'user_id' => $u1->id,
        'author_name' => 'User One',
        'rating' => 5,
        'comment' => 'First five star review.',
        'status' => ReviewStatus::Approved,
    ]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $product->id,
        'user_id' => $u2->id,
        'author_name' => 'User Two',
        'rating' => 3,
        'comment' => 'Second three star review.',
        'status' => ReviewStatus::Approved,
    ]);

    app(ReviewSummaryService::class)->recalculate('product', $product->id);

    Livewire::test('product-reviews-section', ['productId' => $product->id])
        ->assertSee('4.0')
        ->assertSee('Based on 2 reviews');
});

test('empty state renders gracefully when product has no approved reviews', function () {
    extract(createStorefrontReviewContext());

    Livewire::test('product-reviews-section', ['productId' => $product->id])
        ->assertSee('No reviews yet')
        ->assertSee('Be the first verified buyer to share your feedback.')
        ->assertDontSee('Based on');
});

test('unauthenticated visitor is prompted to sign in and cannot open review form', function () {
    extract(createStorefrontReviewContext());

    Livewire::test('product-reviews-section', ['productId' => $product->id])
        ->assertSee('Sign in to review')
        ->call('openReviewForm')
        ->assertRedirect(route('login'));
});

test('ineligible authenticated customer cannot open review form or submit', function () {
    extract(createStorefrontReviewContext());

    $ineligibleUser = User::factory()->create();
    $this->actingAs($ineligibleUser);

    Livewire::test('product-reviews-section', ['productId' => $product->id])
        ->assertSee('Verified Buyers Only')
        ->call('openReviewForm')
        ->assertSet('showForm', false)
        ->assertSee('Only verified buyers');
});

test('eligible customer can submit a review and it auto-publishes immediately', function () {
    extract(createStorefrontReviewContext());
    deliverOrderForStorefront($customer, $seller, $variant);

    $this->actingAs($customer);

    Livewire::test('product-reviews-section', ['productId' => $product->id])
        ->assertSee('Write a Review')
        ->call('openReviewForm')
        ->assertSet('showForm', true)
        ->set('rating', 5)
        ->set('title', 'Excellent earbuds')
        ->set('comment', 'These earbuds have amazing sound quality and great battery life.')
        ->call('saveReview')
        ->assertSet('showForm', false)
        ->assertSee('Thank you! Your review has been published.')
        ->assertSee('Excellent earbuds');

    $review = ProductReview::where('reviewable_id', $product->id)->where('user_id', $customer->id)->first();
    expect($review)->not->toBeNull();
    expect($review->rating)->toBe(5);
    expect($review->status)->toBe(ReviewStatus::Approved);
    expect($review->is_verified_purchase)->toBeTrue();
});

test('customer can edit their existing review and update aggregates', function () {
    extract(createStorefrontReviewContext());
    $item = deliverOrderForStorefront($customer, $seller, $variant);

    $review = ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $product->id,
        'user_id' => $customer->id,
        'author_name' => $customer->name,
        'rating' => 5,
        'title' => 'Initial 5 Star',
        'comment' => 'Initial comment before editing.',
        'status' => ReviewStatus::Approved,
        'is_verified_purchase' => true,
        'order_item_id' => (string) $item->id,
    ]);

    app(ReviewSummaryService::class)->recalculate('product', $product->id);

    $this->actingAs($customer);

    Livewire::test('product-reviews-section', ['productId' => $product->id])
        ->assertSee('Edit Your Review')
        ->call('openReviewForm')
        ->assertSet('showForm', true)
        ->assertSet('rating', 5)
        ->assertSet('title', 'Initial 5 Star')
        ->set('rating', 4)
        ->set('title', 'Updated 4 Star')
        ->set('comment', 'Updated review comment after 2 weeks of use.')
        ->call('saveReview')
        ->assertSet('showForm', false)
        ->assertSee('Your review has been updated successfully.')
        ->assertSee('Updated 4 Star');

    // Confirm exactly 1 review exists and is updated
    expect(ProductReview::where('reviewable_id', $product->id)->count())->toBe(1);
    $review->refresh();
    expect($review->rating)->toBe(4);
    expect($review->title)->toBe('Updated 4 Star');
});

test('server-side validation rejects invalid review inputs', function () {
    extract(createStorefrontReviewContext());
    deliverOrderForStorefront($customer, $seller, $variant);

    $this->actingAs($customer);

    Livewire::test('product-reviews-section', ['productId' => $product->id])
        ->call('openReviewForm')
        ->set('rating', 0)
        ->set('comment', 'Short')
        ->set('title', str_repeat('X', 130))
        ->call('saveReview')
        ->assertHasErrors(['rating', 'comment', 'title']);
});

test('rating filters and sorting function correctly in reviews component', function () {
    extract(createStorefrontReviewContext());

    $u1 = User::factory()->create();
    $u2 = User::factory()->create();

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $product->id,
        'user_id' => $u1->id,
        'author_name' => 'Reviewer One',
        'rating' => 5,
        'title' => 'Five Star Item',
        'comment' => 'Five star review text here.',
        'status' => ReviewStatus::Approved,
    ]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $product->id,
        'user_id' => $u2->id,
        'author_name' => 'Reviewer Two',
        'rating' => 2,
        'title' => 'Two Star Item',
        'comment' => 'Two star review text here.',
        'status' => ReviewStatus::Approved,
    ]);

    app(ReviewSummaryService::class)->recalculate('product', $product->id);

    // Filter by 5-star
    Livewire::test('product-reviews-section', ['productId' => $product->id])
        ->call('setRatingFilter', '5')
        ->assertSee('Five Star Item')
        ->assertDontSee('Two Star Item')
        // Filter by 2-star
        ->call('setRatingFilter', '2')
        ->assertSee('Two Star Item')
        ->assertDontSee('Five Star Item')
        // Clear filter
        ->call('setRatingFilter', 'all')
        ->assertSee('Five Star Item')
        ->assertSee('Two Star Item');
});

test('product detail page displays rating summary near title and reviews section', function () {
    extract(createStorefrontReviewContext());

    $u1 = User::factory()->create();
    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $product->id,
        'user_id' => $u1->id,
        'author_name' => 'Pill Reviewer',
        'rating' => 5,
        'comment' => 'Great product showing in pill.',
        'status' => ReviewStatus::Approved,
    ]);

    app(ReviewSummaryService::class)->recalculate('product', $product->id);

    Livewire::test('pages::storefront.product-show', ['slug' => $product->slug, 'upid' => $variant->upid])
        ->assertSeeHtml('data-test="product-rating-pill"')
        ->assertSee('5.0')
        ->assertSee('(1 review)')
        ->assertSeeHtml('data-test="reviews-section"');
});

test('order page shows Rate & Review for eligible delivered item and Edit Review when already reviewed', function () {
    extract(createStorefrontReviewContext());
    $item = deliverOrderForStorefront($customer, $seller, $variant);

    $this->actingAs($customer);

    $orderNumber = $item->sellerOrder->order->order_number;

    // 1. Delivered, not yet reviewed -> shows Rate & Review
    Livewire::actingAs($customer)->test('pages::storefront.order-show', ['order' => $orderNumber])
        ->assertSeeHtml('data-test="rate-order-item-review"')
        ->assertSee('Rate & Review');

    // 2. Submit a review for this product
    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $product->id,
        'user_id' => $customer->id,
        'author_name' => $customer->name,
        'rating' => 5,
        'comment' => 'Review from order page test.',
        'status' => ReviewStatus::Approved,
        'order_item_id' => (string) $item->id,
    ]);

    // 3. Delivered, already reviewed -> shows Edit Review
    Livewire::actingAs($customer)->test('pages::storefront.order-show', ['order' => $orderNumber])
        ->assertSeeHtml('data-test="edit-order-item-review"')
        ->assertSee('Edit Review')
        ->assertDontSeeHtml('data-test="rate-order-item-review"');
});

test('catalog product card ratings use batching and do not produce N+1 queries', function () {
    extract(createStorefrontReviewContext());

    // Create 4 additional products with reviews and summaries
    for ($i = 1; $i <= 4; $i++) {
        $p = Product::create([
            'vertical_id' => $product->vertical_id,
            'brand_id' => $product->brand_id,
            'title' => "Batch Product {$i}",
            'slug' => "batch-product-{$i}-".uniqid(),
            'description' => "Description {$i}",
            'qc_status' => ProductQcStatus::Approved,
            'buy_box_listing_id' => $listing->id,
            'buy_box_mrp' => 10000,
            'min_price' => 8000,
            'in_stock' => true,
        ]);

        $u = User::factory()->create();
        ProductReview::create([
            'reviewable_type' => 'product',
            'reviewable_id' => $p->id,
            'user_id' => $u->id,
            'author_name' => "Reviewer {$i}",
            'rating' => 4,
            'comment' => "Review comment for batch product {$i}",
            'status' => ReviewStatus::Approved,
        ]);

        app(ReviewSummaryService::class)->recalculate('product', $p->id);
    }

    DB::enableQueryLog();

    $component = Livewire::test('pages::storefront.product-index');

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Count how many queries were made to review_summaries
    $reviewSummaryQueries = array_filter($queries, function ($q) {
        return str_contains($q['query'], 'review_summaries');
    });

    // Exactly 1 batched query for all products on the page
    expect(count($reviewSummaryQueries))->toBeLessThanOrEqual(1);
});
