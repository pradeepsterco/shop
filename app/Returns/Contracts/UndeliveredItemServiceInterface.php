<?php

namespace App\Returns\Contracts;

use App\Returns\Models\UndeliveredItem;

/**
 * The admin queue of units the courier did not deliver on a partial delivery. Each item gets one decision at a time:
 * refund, or reship. Settlement reversals for these units already happened when the partial delivery was confirmed.
 */
interface UndeliveredItemServiceInterface
{
    /**
     * Refund the customer for the units (COD: nothing was collected, so ₹0 and not_collected) and release their coupon
     * share. No settlement change.
     */
    public function refund(string $undeliveredItemId, int $adminId): UndeliveredItem;

    /**
     * Reship the units: take stock from the original listing, create a reship shipment collecting what is still owed,
     * and charge the seller the reship shipping. Not allowed when out of stock or the listing is not active.
     */
    public function reship(string $undeliveredItemId, int $adminId): UndeliveredItem;

    /**
     * The reship was delivered: re-credit the seller for these units and hold them; mark the order delivered when
     * nothing is left undelivered.
     */
    public function reshipDelivered(string $reshipShipmentId, string $deliveredAt): void;

    /**
     * The reship came back to the seller: restock, charge reship RTO shipping, and put the items back in the queue.
     */
    public function reshipReturned(string $reshipShipmentId): void;

    /**
     * The reship was cancelled before shipping: restock, reverse the reship shipping charge, items back in the queue.
     */
    public function reshipCancelled(string $reshipShipmentId): void;
}
