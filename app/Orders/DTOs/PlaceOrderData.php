<?php

namespace App\Orders\DTOs;

readonly class PlaceOrderData
{
    /**
     * @param  array<int, array{listing_id: int, qty: int}>  $lines
     */
    public function __construct(
        public int $userId,
        public int $addressId,
        public string $paymentMethod,
        public array $lines,
        public int $expectedTotal,
        public string $idempotencyKey,
        public string $source = 'cart',
    ) {}
}
