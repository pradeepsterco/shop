<?php

namespace App\Orders\Contracts;

use App\Orders\DTOs\ReturnableOrderDto;

interface ReturnableOrderLookupInterface
{
    /**
     * A seller package as the Returns module needs it: owner, status, delivery date and each line's quantity and
     * whether its vertical is returnable.
     */
    public function forSellerOrder(string $sellerOrderId): ?ReturnableOrderDto;
}
