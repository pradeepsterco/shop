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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('[Actual DB] database unique constraint rejects duplicate reviews for same user and product', function () {
    $user = User::factory()->create();
    $productId = 500;

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $productId,
        'user_id' => $user->id,
        'author_name' => 'Alice',
        'rating' => 5,
        'comment' => 'First review comment here.',
        'status' => ReviewStatus::Approved,
        'is_verified_purchase' => true,
    ]);

    // Attempting raw DB insert with identical (reviewable_type, reviewable_id, user_id)
    expect(function () use ($user, $productId) {
        ProductReview::create([
            'reviewable_type' => 'product',
            'reviewable_id' => $productId,
            'user_id' => $user->id,
            'author_name' => 'Alice Again',
            'rating' => 4,
            'comment' => 'Second duplicate review attempt.',
            'status' => ReviewStatus::Approved,
            'is_verified_purchase' => true,
        ]);
    })->toThrow(UniqueConstraintViolationException::class);
});

test('[Simulated Concurrency] submitOrUpdate recovers from concurrent insert race by updating existing review', function () {
    $eligibilityMock = Mockery::mock(ReviewEligibilityInterface::class);
    $summaryService = new ReviewSummaryService;

    $user = User::factory()->create(['name' => 'Concurrent User']);
    $productId = 501;

    $eligibilityMock->shouldReceive('check')
        ->with($user->id, $productId)
        ->andReturn(EligibilityResult::eligible('item_init', 10, 5));

    // Create a subclass or test spy of ProductReviewService to simulate the exact race:
    // Thread A checks $existing (returns null), but before Thread A calls create(),
    // Thread B commits an initial review with the same (user_id, product_id).
    $service = new class($eligibilityMock, $summaryService) extends ProductReviewService
    {
        public bool $simulatedRaceTriggered = false;

        protected function performSubmitOrUpdate(
            User $user,
            int $productId,
            ReviewSubmissionData $data,
            string $comment,
            ?string $title
        ): ProductReview {
            if (! $this->simulatedRaceTriggered) {
                $this->simulatedRaceTriggered = true;

                // Simulate winning concurrent thread committing a review right now:
                ProductReview::create([
                    'reviewable_type' => 'product',
                    'reviewable_id' => $productId,
                    'user_id' => $user->id,
                    'author_name' => 'Winning Thread',
                    'rating' => 3,
                    'comment' => 'Initial comment by winning concurrent thread.',
                    'status' => ReviewStatus::Approved,
                    'is_verified_purchase' => true,
                    'order_item_id' => 'item_init',
                    'product_variant_id' => 10,
                    'seller_id' => 5,
                ]);

                // Thread A now attempts its insert and hits UniqueConstraintViolationException
                throw new UniqueConstraintViolationException('test_connection', 'INSERT INTO product_reviews ...', [], new Exception('Simulated duplicate key'));
            }

            return parent::performSubmitOrUpdate($user, $productId, $data, $comment, $title);
        }
    };

    $submissionData = new ReviewSubmissionData(
        rating: 5,
        comment: 'Recovered comment that should overwrite winning race comment.',
        title: 'Updated After Race',
        variantId: 10,
        orderItemId: 'item_init',
        sellerId: 5,
    );

    $finalReview = $service->submitOrUpdate($user, $productId, $submissionData);

    // Verify recovery: Exactly 1 review exists, updated with thread A's data, preserving verified purchase
    expect(ProductReview::where('reviewable_id', $productId)->count())->toBe(1);
    expect($finalReview->rating)->toBe(5);
    expect($finalReview->title)->toBe('Updated After Race');
    expect($finalReview->comment)->toBe('Recovered comment that should overwrite winning race comment.');
    expect($finalReview->order_item_id)->toBe('item_init');
    expect($finalReview->is_verified_purchase)->toBeTrue();

    // Verify summary aggregate reflects the recovered review rating (5.00)
    $summary = ReviewSummary::where('reviewable_id', $productId)->first();
    expect($summary->rating_count)->toBe(1);
    expect($summary->rating_avg)->toBe(5.00);
});

test('[Actual DB] ReviewSummaryService handles first-time creation and subsequent recalculations safely', function () {
    $summaryService = new ReviewSummaryService;
    $productId = 601;
    $user = User::factory()->create();

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $productId,
        'user_id' => $user->id,
        'author_name' => 'Reviewer',
        'rating' => 4,
        'comment' => 'Good product for summary creation test.',
        'status' => ReviewStatus::Approved,
        'is_verified_purchase' => true,
    ]);

    // First recalculation creates summary row
    $summary1 = $summaryService->recalculate('product', $productId);
    expect($summary1->rating_count)->toBe(1);
    expect($summary1->rating_avg)->toBe(4.00);

    // Second recalculation locks and updates existing summary row without creating duplicate
    $summary2 = $summaryService->recalculate('product', $productId);
    expect($summary2->id)->toBe($summary1->id);
    expect(ReviewSummary::where('reviewable_id', $productId)->count())->toBe(1);
});

test('service enforces input validation rules and respects feature flag', function () {
    $eligibilityMock = Mockery::mock(ReviewEligibilityInterface::class);
    $summaryService = new ReviewSummaryService;
    $service = new ProductReviewService($eligibilityMock, $summaryService);
    $user = User::factory()->create();

    // 1. Rating < 1
    expect(fn () => $service->submitOrUpdate($user, 1, new ReviewSubmissionData(
        rating: 0,
        comment: 'Valid comment length here'
    )))->toThrow(DomainException::class, 'Rating must be an integer between 1 and 5.');

    // 2. Rating > 5
    expect(fn () => $service->submitOrUpdate($user, 1, new ReviewSubmissionData(
        rating: 6,
        comment: 'Valid comment length here'
    )))->toThrow(DomainException::class, 'Rating must be an integer between 1 and 5.');

    // 3. Comment too short (< 10 chars)
    expect(fn () => $service->submitOrUpdate($user, 1, new ReviewSubmissionData(
        rating: 5,
        comment: 'Short'
    )))->toThrow(DomainException::class, 'Review comment must be between 10 and 2000 characters.');

    // 4. Title too long (> 120 chars)
    expect(fn () => $service->submitOrUpdate($user, 1, new ReviewSubmissionData(
        rating: 5,
        comment: 'Valid comment length here',
        title: str_repeat('A', 121)
    )))->toThrow(DomainException::class, 'Review title cannot exceed 120 characters.');

    // 5. Disabled feature flag
    config(['reviews.enabled' => false]);
    expect(fn () => $service->submitOrUpdate($user, 1, new ReviewSubmissionData(
        rating: 5,
        comment: 'Valid comment length here'
    )))->toThrow(DomainException::class, 'Product reviews are currently disabled.');
});
