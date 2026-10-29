<?php

namespace App\Reviews\Services;

use App\Models\User;
use App\Reviews\Contracts\ReviewEligibilityInterface;
use App\Reviews\DTOs\EligibilityResult;
use App\Reviews\DTOs\ReviewSubmissionData;
use App\Reviews\Enums\ReviewStatus;
use App\Reviews\Models\ProductReview;
use App\Reviews\Models\ReviewSummary;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ProductReviewService
{
    public function __construct(
        protected ReviewEligibilityInterface $eligibility,
        protected ReviewSummaryService $summaryService,
    ) {}

    /**
     * Check if a customer is eligible to write or edit a review.
     */
    public function checkEligibility(User $user, int $productId): EligibilityResult
    {
        if (! config('reviews.enabled', true)) {
            return EligibilityResult::ineligible('Product reviews are currently disabled.');
        }

        return $this->eligibility->check($user->id, $productId);
    }

    /**
     * Submit or update a review transactionally.
     * Enforces exactly one review per user per product. If a review already exists
     * (e.g. from a prior order of the same product), it updates the existing review
     * while retaining valid verified-purchase linkage.
     *
     * In case of concurrent initial submissions, recovers safely from unique constraint
     * violations by locking and updating the winner's inserted review.
     */
    public function submitOrUpdate(User $user, int $productId, ReviewSubmissionData $data): ProductReview
    {
        if (! config('reviews.enabled', true)) {
            throw new DomainException('Product reviews are currently disabled.');
        }

        // Validate rating range
        if ($data->rating < 1 || $data->rating > 5) {
            throw new DomainException('Rating must be an integer between 1 and 5.');
        }

        // Validate comment length
        $comment = trim($data->comment);
        $minLen = (int) config('reviews.min_comment_length', 10);
        $maxLen = (int) config('reviews.max_comment_length', 2000);
        if (mb_strlen($comment) < $minLen || mb_strlen($comment) > $maxLen) {
            throw new DomainException("Review comment must be between {$minLen} and {$maxLen} characters.");
        }

        // Validate title length
        $title = $data->title !== null ? trim($data->title) : null;
        $maxTitleLen = (int) config('reviews.max_title_length', 120);
        if ($title !== null && mb_strlen($title) > $maxTitleLen) {
            throw new DomainException("Review title cannot exceed {$maxTitleLen} characters.");
        }

        try {
            return $this->performSubmitOrUpdate($user, $productId, $data, $comment, $title);
        } catch (UniqueConstraintViolationException) {
            // A concurrent initial submission won the insert race. Recover by re-executing
            // the operation; the query will now lock and update the committed review row.
            return $this->performSubmitOrUpdate($user, $productId, $data, $comment, $title);
        }
    }

    /**
     * Internal atomic execution of submission or update.
     */
    protected function performSubmitOrUpdate(
        User $user,
        int $productId,
        ReviewSubmissionData $data,
        string $comment,
        ?string $title
    ): ProductReview {
        return DB::transaction(function () use ($user, $productId, $data, $comment, $title) {
            // Pessimistic lock on any existing review for this user and product
            /** @var ProductReview|null $existing */
            $existing = ProductReview::query()
                ->where('reviewable_type', 'product')
                ->where('reviewable_id', $productId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            // Verify delivery eligibility
            $eligibility = $this->eligibility->check($user->id, $productId);

            if (! $existing && ! $eligibility->canReview) {
                throw new DomainException($eligibility->reason ?? 'You must have a delivered order for this product to write a review.');
            }

            $authorName = trim($user->name) ?: 'Customer';

            if ($existing) {
                // Update existing review
                $existing->update([
                    'rating' => $data->rating,
                    'title' => $title,
                    'comment' => $comment,
                    'author_name' => $authorName,
                    // Auto-approve edits or retain approved status
                    'status' => ReviewStatus::Approved,
                    // If existing had no variant/order_item linkage but eligibility found one, enrich it
                    'product_variant_id' => $existing->product_variant_id ?: $data->variantId ?: $eligibility->variantId,
                    'order_item_id' => $existing->order_item_id ?: $data->orderItemId ?: $eligibility->orderItemId,
                    'seller_id' => $existing->seller_id ?: $data->sellerId ?: $eligibility->sellerId,
                ]);

                $review = $existing;
            } else {
                // Create new review
                /** @var ProductReview $review */
                $review = ProductReview::create([
                    'reviewable_type' => 'product',
                    'reviewable_id' => $productId,
                    'user_id' => $user->id,
                    'author_name' => $authorName,
                    'rating' => $data->rating,
                    'title' => $title,
                    'comment' => $comment,
                    'is_verified_purchase' => true,
                    'status' => ReviewStatus::Approved,
                    'product_variant_id' => $data->variantId ?: $eligibility->variantId,
                    'order_item_id' => $data->orderItemId ?: $eligibility->orderItemId,
                    'seller_id' => $data->sellerId ?: $eligibility->sellerId,
                ]);
            }

            // Immediately and atomically recalculate the summary aggregate
            $this->summaryService->recalculate('product', $productId);

            return $review;
        });
    }

    /**
     * Admin moderation: update status (e.g. hide, reject, restore).
     */
    public function setStatus(int $reviewId, ReviewStatus $newStatus): ProductReview
    {
        return DB::transaction(function () use ($reviewId, $newStatus) {
            /** @var ProductReview $review */
            $review = ProductReview::findOrFail($reviewId);
            $review->update(['status' => $newStatus]);

            $this->summaryService->recalculate($review->reviewable_type, $review->reviewable_id);

            return $review;
        });
    }

    /**
     * Admin action: permanently delete a review.
     */
    public function deleteReview(int $reviewId): bool
    {
        return DB::transaction(function () use ($reviewId) {
            /** @var ProductReview $review */
            $review = ProductReview::findOrFail($reviewId);
            $type = $review->reviewable_type;
            $id = $review->reviewable_id;

            $deleted = (bool) $review->delete();

            $this->summaryService->recalculate($type, $id);

            return $deleted;
        });
    }

    /**
     * Get review summary for a product.
     */
    public function getSummary(int $productId): ?ReviewSummary
    {
        return $this->summaryService->getSummary('product', $productId);
    }
}
