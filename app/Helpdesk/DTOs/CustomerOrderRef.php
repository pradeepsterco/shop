<?php

namespace App\Helpdesk\DTOs;

/**
 * A customer's order as the helpdesk sees it: enough to link and label a ticket.
 */
final readonly class CustomerOrderRef
{
    public function __construct(
        public string $orderId,
        public string $orderNumber,
        public string $placedAt,
        public string $summary,
    ) {}
}
