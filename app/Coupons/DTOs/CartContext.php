<?php

namespace App\Coupons\DTOs;

use DateTimeInterface;

final readonly class CartContext
{
    /**
     * @param  array<int, CartLineContext>  $lines
     */
    public function __construct(
        public string $customerReference,
        public array $lines,
        public int $shippingAmount = 0,
        public ?DateTimeInterface $now = null,
    ) {}
}
