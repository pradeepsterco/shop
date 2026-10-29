<?php

namespace App\Reviews\DTOs;

readonly class ReviewSubmissionData
{
    public function __construct(
        public int $rating,
        public string $comment,
        public ?string $title = null,
        public ?int $variantId = null,
        public ?string $orderItemId = null,
        public ?int $sellerId = null,
    ) {}
}
