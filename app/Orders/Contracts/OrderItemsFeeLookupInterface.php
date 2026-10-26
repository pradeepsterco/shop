<?php

namespace App\Orders\Contracts;

use App\Orders\DTOs\OrderItemFeeContextDto;

interface OrderItemsFeeLookupInterface
{
    /**
     * Get contextual snapshot data for all items in an order.
     *
     * @return array<int, OrderItemFeeContextDto>
     */
    public function getOrderItemsForFeeCalculation(string $orderId): array;

    /**
     * Get items across orders that need fee snapshot calculation, excluding already-processed items.
     *
     * @param  array<int, int>  $excludeOrderItemIds
     * @return array<int, OrderItemFeeContextDto>
     */
    public function getUnprocessedOrderItemsForBackfill(array $excludeOrderItemIds = [], ?int $limit = null): array;
}
