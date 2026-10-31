<?php

namespace App\Helpdesk\Contracts;

use App\Helpdesk\DTOs\CustomerOrderRef;

interface CustomerOrderLookupInterface
{
    /**
     * The customer's order with this order number, or null when it is not theirs.
     */
    public function findForCustomer(int $userId, string $orderNumber): ?CustomerOrderRef;

    /**
     * The customer's most recent orders, newest first.
     *
     * @return list<CustomerOrderRef>
     */
    public function recentForCustomer(int $userId, int $limit = 20): array;
}
