<?php

namespace App\Helpdesk\DTOs;

/**
 * A seller's package (seller order) as the helpdesk sees it: enough to link and label a ticket.
 */
final readonly class SellerOrderRef
{
    public function __construct(
        public string $sellerOrderId,
        public ?int $sellerProfileId,
        public string $orderId,
        public string $orderNumber,
        public string $placedAt,
        public string $summary,
    ) {}
}
