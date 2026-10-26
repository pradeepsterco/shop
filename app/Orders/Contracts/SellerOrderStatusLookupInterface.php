<?php

namespace App\Orders\Contracts;

use App\Orders\DTOs\SellerOrderStatusDto;

interface SellerOrderStatusLookupInterface
{
    /**
     * Look up seller order status, delivery timestamp, and cancellation timestamp.
     */
    public function getSellerOrderStatus(string $sellerOrderId): ?SellerOrderStatusDto;
}
