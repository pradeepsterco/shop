<?php

namespace App\Orders\Contracts;

use App\Orders\DTOs\SellerOrderShippingDetailsDto;

interface SellerOrderShippingDetailsLookupInterface
{
    /**
     * Retrieve full shipping and fulfillment details for a seller order.
     */
    public function getShippingDetails(string $sellerOrderId): ?SellerOrderShippingDetailsDto;
}
