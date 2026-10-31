<?php

namespace App\Helpdesk\Contracts;

use App\Helpdesk\DTOs\SellerOrderRef;

interface SellerOrderLookupInterface
{
    /**
     * The seller's package with this seller order id, or null when it is not theirs.
     */
    public function findForSeller(int $sellerProfileId, string $sellerOrderId): ?SellerOrderRef;

    /**
     * The seller's most recent packages, newest first.
     *
     * @return list<SellerOrderRef>
     */
    public function recentForSeller(int $sellerProfileId, int $limit = 30): array;

    /**
     * Every seller's package within one customer order (one per seller).
     *
     * @return list<SellerOrderRef>
     */
    public function forOrder(string $orderId): array;
}
