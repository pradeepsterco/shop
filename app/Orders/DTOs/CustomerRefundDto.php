<?php

namespace App\Orders\DTOs;

use App\Orders\Enums\RefundStatus;

final readonly class CustomerRefundDto
{
    /**
     * @param  array<int, int>  $units  order item id => units refunded
     */
    public function __construct(
        public string $id,
        public string $sellerOrderId,
        public string $sourceType,
        public string $sourceId,
        public int $amountPaise,
        public RefundStatus $status,
        public array $units,
    ) {}
}
