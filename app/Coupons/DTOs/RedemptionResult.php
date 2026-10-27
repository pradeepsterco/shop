<?php

namespace App\Coupons\DTOs;

final readonly class RedemptionResult
{
    /**
     * @param  array<string|int, int>  $lineAllocations
     */
    public function __construct(
        public bool $success,
        public ?int $redemptionId,
        public int $discountAmount,
        public array $lineAllocations,
        public ?string $errorMessage = null,
    ) {}

    public static function failed(string $message): self
    {
        return new self(
            success: false,
            redemptionId: null,
            discountAmount: 0,
            lineAllocations: [],
            errorMessage: $message,
        );
    }
}
