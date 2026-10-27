<?php

namespace App\Coupons\DTOs;

final readonly class RedemptionReleaseResult
{
    public function __construct(
        public bool $success,
        public int $releasedAmount,
        public int $cumulativeReleasedAmount,
        public string $status,
        public ?string $errorMessage = null,
    ) {}

    public static function failed(string $message): self
    {
        return new self(
            success: false,
            releasedAmount: 0,
            cumulativeReleasedAmount: 0,
            status: 'failed',
            errorMessage: $message,
        );
    }
}
