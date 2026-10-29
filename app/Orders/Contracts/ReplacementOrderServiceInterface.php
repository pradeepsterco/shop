<?php

namespace App\Orders\Contracts;

interface ReplacementOrderServiceInterface
{
    /**
     * Create a linked ₹0 replacement order (same listing, same delivery address snapshot) for units of a returned
     * package. It goes through the normal forward flow (the seller confirms, packs and ships it); no fees are
     * calculated for it. Stock is reserved on the original listing. Database only (safe inside a transaction).
     * Throws DomainException when a listing is out of stock or not active. Returns the new seller order id.
     *
     * @param  array<int, int>  $units  original order item id => units
     */
    public function createReplacement(string $originalSellerOrderId, array $units, string $returnRequestId): string;

    /**
     * Is this seller order a replacement created by a return? (Returning it again can only be refunded.)
     */
    public function isReplacement(string $sellerOrderId): bool;
}
