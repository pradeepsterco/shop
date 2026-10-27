<?php

namespace App\Coupons\DTOs;

final readonly class CouponEvaluation
{
    /**
     * @param  array<string|int, int>  $lineAllocations  [line_id => discount_paise]
     */
    public function __construct(
        public bool $isValid,
        public ?string $errorCode,
        public ?string $message,
        public int $totalDiscount,
        public array $lineAllocations,
        public ?string $fundedBy,
        public ?int $couponId,
        public ?string $couponCode,
    ) {}

    public static function invalid(string $errorCode, string $message, ?string $couponCode = null): self
    {
        return new self(
            isValid: false,
            errorCode: $errorCode,
            message: $message,
            totalDiscount: 0,
            lineAllocations: [],
            fundedBy: null,
            couponId: null,
            couponCode: $couponCode,
        );
    }
}
