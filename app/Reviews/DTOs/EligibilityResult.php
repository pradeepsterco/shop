<?php

namespace App\Reviews\DTOs;

readonly class EligibilityResult
{
    public function __construct(
        public bool $canReview,
        public bool $hasExistingReview,
        public ?int $existingReviewId = null,
        public ?int $variantId = null,
        public ?string $orderItemId = null,
        public ?int $sellerId = null,
        public ?string $reason = null,
    ) {}

    public static function eligible(
        ?string $orderItemId = null,
        ?int $variantId = null,
        ?int $sellerId = null,
        bool $hasExistingReview = false,
        ?int $existingReviewId = null,
    ): self {
        return new self(
            canReview: true,
            hasExistingReview: $hasExistingReview,
            existingReviewId: $existingReviewId,
            variantId: $variantId,
            orderItemId: $orderItemId,
            sellerId: $sellerId,
            reason: null,
        );
    }

    public static function ineligible(string $reason, bool $hasExistingReview = false, ?int $existingReviewId = null): self
    {
        return new self(
            canReview: false,
            hasExistingReview: $hasExistingReview,
            existingReviewId: $existingReviewId,
            variantId: null,
            orderItemId: null,
            sellerId: null,
            reason: $reason,
        );
    }
}
