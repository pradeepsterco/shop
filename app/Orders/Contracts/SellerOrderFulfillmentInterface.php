<?php

namespace App\Orders\Contracts;

use App\Orders\DTOs\SellerOrderInvoiceDto;
use DateTimeInterface;

interface SellerOrderFulfillmentInterface
{
    /**
     * Get or generate a GST-compliant invoice number for the seller order.
     * Ensures sequence gap-free uniqueness per seller per financial year.
     * Enforces strict 16-character limit and format: S{seller_profile_id}/{FY}/{zero_padded_seq}.
     */
    public function getOrCreateInvoiceNumber(string $sellerOrderId): SellerOrderInvoiceDto;

    /**
     * Mark the seller order as Shipped with courier and tracking details.
     */
    public function markShipped(
        string $sellerOrderId,
        string $courierName,
        string $trackingNumber,
        ?DateTimeInterface $shippedAt = null,
    ): void;

    /**
     * Mark the seller order as Delivered.
     */
    public function markDelivered(
        string $sellerOrderId,
        ?DateTimeInterface $deliveredAt = null,
    ): void;

    /**
     * Mark the seller order as Returned To Origin (RTO).
     */
    public function markReturnedToOrigin(
        string $sellerOrderId,
        ?string $reason = null,
    ): void;

    /**
     * Mark a shipped seller order as Lost in Transit (courier lost it). The customer is not charged;
     * stock is not restored because the goods are gone.
     */
    public function markLostInTransit(
        string $sellerOrderId,
        ?string $reason = null,
    ): void;

    /**
     * Admin-confirmed outcome of a partial delivery: the seller order becomes Partially Delivered and, for COD,
     * records the amount to collect for the delivered items only. With no undelivered items it is simply Delivered.
     *
     * Returns the undelivered lines (whole lines: units = line quantity).
     *
     * @param  array<int>  $deliveredItemIds
     * @param  array<int>  $undeliveredItemIds
     * @return array<int, array{units: int, line_qty: int}>
     */
    public function confirmPartialDelivery(
        string $sellerOrderId,
        array $deliveredItemIds,
        array $undeliveredItemIds,
    ): array;

    /**
     * A reship of undelivered items was delivered: add the COD collected for it and, once nothing is left
     * undelivered, mark the seller order Delivered.
     */
    public function recordReshipDelivered(string $sellerOrderId, int $codCollectedPaise, bool $allItemsDelivered): void;

    /**
     * After a return refund: the seller order becomes Partially Refunded, or Refunded once every unit is refunded.
     */
    public function recordReturnRefund(string $sellerOrderId): void;

    /**
     * Mark the seller order as Partial Delivered.
     */
    public function markPartialDelivered(
        string $sellerOrderId,
        ?string $reason = null,
    ): void;

    /**
     * Revert seller order status to Packed upon shipment cancellation.
     */
    public function revertToPacked(
        string $sellerOrderId,
        ?string $reason = null,
    ): void;
}
