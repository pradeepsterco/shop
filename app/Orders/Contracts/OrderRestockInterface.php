<?php

namespace App\Orders\Contracts;

interface OrderRestockInterface
{
    /**
     * Restock inventory for items in a seller order and refresh buy box.
     *
     * @param  array<int>|null  $orderItemIds  Specific items to restock, or null for all
     */
    public function restockSellerOrder(string $sellerOrderId, ?array $orderItemIds = null): void;

    /**
     * Take stock from the item's original listing for a reship. Throws if the listing is not active or does not
     * have the units in stock (the admin must refund instead).
     */
    public function reserveStockForReship(int $orderItemId, int $units): void;

    /**
     * Put units of an order item back on its listing (e.g. a reship that was cancelled or came back).
     */
    public function restockOrderItemUnits(int $orderItemId, int $units): void;
}
