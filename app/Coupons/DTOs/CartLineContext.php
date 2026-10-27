<?php

namespace App\Coupons\DTOs;

final readonly class CartLineContext
{
    /**
     * @param  array<int, int>  $categoryIds
     */
    public function __construct(
        public string|int $lineId,
        public int $sellerId,
        public int $productId,
        public array $categoryIds,
        public ?int $brandId,
        public int $unitPrice,
        public int $quantity,
        public int $lineTotal,
    ) {}
}
