<?php

namespace App\Orders\DTOs;

use App\Orders\Enums\SellerOrderStatus;
use DateTimeInterface;

final readonly class ReturnableOrderDto
{
    /**
     * @param  array<int, ReturnableItemDto>  $items  keyed by order item id
     */
    public function __construct(
        public string $sellerOrderId,
        public string $orderId,
        public string $orderNumber,
        public int $userId,
        public SellerOrderStatus $status,
        public ?DateTimeInterface $deliveredAt,
        public array $items,
        public int $sellerProfileId = 0,
    ) {}
}
