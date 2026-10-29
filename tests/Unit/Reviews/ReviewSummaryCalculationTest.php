<?php

use App\Models\User;
use App\Reviews\Contracts\ReviewEligibilityInterface;
use App\Reviews\DTOs\EligibilityResult;
use App\Reviews\DTOs\ReviewSubmissionData;
use App\Reviews\Enums\ReviewStatus;
use App\Reviews\Models\ProductReview;
use App\Reviews\Models\ReviewSummary;
use App\Reviews\Services\ProductReviewService;
use App\Reviews\Services\ReviewSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('summary recalculates count, average, and star distribution accurately for approved reviews only', function () {
    $summaryService = new ReviewSummaryService;
    $productId = 101;

    // Create a mock user
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $user3 = User::factory()->create();
    $user4 = User::factory()->create();

    // 4 approved reviews: ratings 5, 5, 4, 2 -> sum = 16, count = 4, avg = 4.00
    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $productId,
        'user_id' => $user1->id,
        'author_name' => 'User 1',
        'rating' => 5,
        'comment' => 'Excellent quality product!',
        'status' => ReviewStatus::Approved,
        'is_verified_purchase' => true,
    ]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $productId,
        'user_id' => $user2->id,
        'author_name' => 'User 2',
        'rating' => 5,
        'comment' => 'Truly exceeded my expectations.',
        'status' => ReviewStatus::Approved,
        'is_verified_purchase' => true,
    ]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $productId,
        'user_id' => $user3->id,
        'author_name' => 'User 3',
        'rating' => 4,
        'comment' => 'Very good, minor packaging scuffs.',
        'status' => ReviewStatus::Approved,
        'is_verified_purchase' => true,
    ]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $productId,
        'user_id' => $user4->id,
        'author_name' => 'User 4',
        'rating' => 2,
        'comment' => 'Did not meet expectations.',
        'status' => ReviewStatus::Approved,
        'is_verified_purchase' => true,
    ]);

    // Hidden and rejected reviews (must be ignored in aggregate)
    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $productId,
        'user_id' => null,
        'author_name' => 'Hidden User',
        'rating' => 1,
        'comment' => 'Spam comment should be excluded.',
        'status' => ReviewStatus::Hidden,
        'is_verified_purchase' => true,
    ]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $productId,
        'user_id' => null,
        'author_name' => 'Rejected User',
        'rating' => 1,
        'comment' => 'Abusive content should be excluded.',
        'status' => ReviewStatus::Rejected,
        'is_verified_purchase' => true,
    ]);

    $summary = $summaryService->recalculate('product', $productId);

    expect($summary->rating_count)->toBe(4);
    expect($summary->rating_avg)->toBe(4.00);
    expect($summary->star_5)->toBe(2);
    expect($summary->star_4)->toBe(1);
    expect($summary->star_3)->toBe(0);
    expect($summary->star_2)->toBe(1);
    expect($summary->star_1)->toBe(0);

    // Test getStarPercentage
    expect($summary->getStarPercentage(5))->toBe(50); // 2/4 = 50%
    expect($summary->getStarPercentage(4))->toBe(25); // 1/4 = 25%
    expect($summary->getStarPercentage(3))->toBe(0);  // 0%
    expect($summary->getStarPercentage(2))->toBe(25); // 1/4 = 25%
    expect($summary->getStarPercentage(1))->toBe(0);  // 0%
});

test('service enforces exactly one review per user per product and updates on subsequent submissions', function () {
    $eligibilityMock = Mockery::mock(ReviewEligibilityInterface::class);
    $summaryService = new ReviewSummaryService;
    $service = new ProductReviewService($eligibilityMock, $summaryService);

    $user = User::factory()->create(['name' => 'John Doe']);
    $productId = 205;

    // First submission: eligible with first order item
    $eligibilityMock->shouldReceive('check')
        ->with($user->id, $productId)
        ->andReturn(EligibilityResult::eligible(
            orderItemId: 'item_order_1',
            variantId: 10,
            sellerId: 3,
            hasExistingReview: false,
        ));

    $data1 = new ReviewSubmissionData(
        rating: 4,
        comment: 'Great product from first order!',
        title: 'Initial Review',
        variantId: 10,
        orderItemId: 'item_order_1',
        sellerId: 3,
    );

    $review1 = $service->submitOrUpdate($user, $productId, $data1);

    expect($review1->rating)->toBe(4);
    expect($review1->title)->toBe('Initial Review');
    expect($review1->order_item_id)->toBe('item_order_1');
    expect($review1->is_verified_purchase)->toBeTrue();
    expect(ProductReview::where('reviewable_id', $productId)->count())->toBe(1);

    $summary = ReviewSummary::where('reviewable_id', $productId)->first();
    expect($summary->rating_count)->toBe(1);
    expect($summary->rating_avg)->toBe(4.00);
    expect($summary->star_4)->toBe(1);

    // Second submission: customer bought the same product again in a second order
    $eligibilityMock->shouldReceive('check')
        ->with($user->id, $productId)
        ->andReturn(EligibilityResult::eligible(
            orderItemId: 'item_order_2',
            variantId: 10,
            sellerId: 3,
            hasExistingReview: true,
            existingReviewId: $review1->id,
        ));

    $data2 = new ReviewSubmissionData(
        rating: 5,
        comment: 'Updated review after reordering! Even better now.',
        title: 'Updated Review',
        variantId: 10,
        orderItemId: 'item_order_2',
        sellerId: 3,
    );

    $review2 = $service->submitOrUpdate($user, $productId, $data2);

    // Assert same review updated, not duplicated
    expect($review2->id)->toBe($review1->id);
    expect($review2->rating)->toBe(5);
    expect($review2->title)->toBe('Updated Review');
    expect($review2->comment)->toBe('Updated review after reordering! Even better now.');
    expect(ProductReview::where('reviewable_id', $productId)->count())->toBe(1);

    // Assert summary updated to 5.00
    $summary->refresh();
    expect($summary->rating_count)->toBe(1);
    expect($summary->rating_avg)->toBe(5.00);
    expect($summary->star_5)->toBe(1);
    expect($summary->star_4)->toBe(0);
});

test('moderation status change and delete atomically update aggregates', function () {
    $eligibilityMock = Mockery::mock(ReviewEligibilityInterface::class);
    $summaryService = new ReviewSummaryService;
    $service = new ProductReviewService($eligibilityMock, $summaryService);

    $user = User::factory()->create();
    $productId = 301;

    $eligibilityMock->shouldReceive('check')
        ->andReturn(EligibilityResult::eligible('item_1', 1, 1));

    $review = $service->submitOrUpdate($user, $productId, new ReviewSubmissionData(
        rating: 5,
        comment: 'A wonderful product indeed!'
    ));

    expect($service->getSummary($productId)->rating_count)->toBe(1);
    expect($service->getSummary($productId)->rating_avg)->toBe(5.00);

    // Hide review
    $service->setStatus($review->id, ReviewStatus::Hidden);
    expect($service->getSummary($productId)->rating_count)->toBe(0);
    expect($service->getSummary($productId)->rating_avg)->toBe(0.00);

    // Restore to Approved
    $service->setStatus($review->id, ReviewStatus::Approved);
    expect($service->getSummary($productId)->rating_count)->toBe(1);
    expect($service->getSummary($productId)->rating_avg)->toBe(5.00);

    // Delete review
    $service->deleteReview($review->id);
    expect($service->getSummary($productId)->rating_count)->toBe(0);
    expect($service->getSummary($productId)->rating_avg)->toBe(0.00);
});
