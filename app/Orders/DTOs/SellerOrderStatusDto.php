<?php

namespace App\Orders\DTOs;

use DateTimeInterface;

final readonly class SellerOrderStatusDto
{
    public function __construct(
        public string $sellerOrderId,
        public int $sellerProfileId,
        public string $status,
        public ?string $orderId = null,
        public ?DateTimeInterface $deliveredAt = null,
        public ?DateTimeInterface $cancelledAt = null,
        public ?DateTimeInterface $placedAt = null,
    ) {}
}
