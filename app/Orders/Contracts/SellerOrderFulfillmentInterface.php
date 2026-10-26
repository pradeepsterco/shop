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
     * Revert seller order status to Packed upon shipment cancellation.
     */
    public function revertToPacked(
        string $sellerOrderId,
        ?string $reason = null,
    ): void;
}
