<?php

namespace App\Shipping\DTOs;

namespace App\Shipping\Contracts;

use App\Shipping\DTOs\ShipmentSummaryDto;
use App\Shipping\DTOs\TrackingStatusDto;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use App\Shipping\Models\ShipmentEvent;

interface ShipmentServiceInterface
{
    /**
     * Create and manifest a shipment for a seller order.
     * Generates invoice number, calculates volumetric & chargeable weights,
     * calls carrier driver, stores shipment, downloads label, and marks seller order as shipped.
     */
    public function createShipmentForSellerOrder(
        string $sellerOrderId,
        int $actualWeightGrams,
        int $lengthCm,
        int $widthCm,
        int $heightCm,
        ?string $pickupDate = null,
    ): Shipment;

    /**
     * Reship undelivered units: a forward shipment (purpose reship, parent = the original shipment) carrying only those
     * units and collecting $codAmountPaise. Database only (safe inside a transaction); call bookShipment() after commit.
     *
     * @param  array<int, int>  $units  order item id => units
     */
    public function createReshipBooking(string $sellerOrderId, array $units, int $codAmountPaise, int $declaredValuePaise): ShipmentSummaryDto;

    /**
     * Ship returned units back to the customer (QC failed): forward, purpose ship_back, nothing collected.
     * Database only; call bookShipment() after commit.
     *
     * @param  array<int, int>  $units  order item id => units
     */
    public function createShipBackBooking(string $sellerOrderId, array $units, int $declaredValuePaise): ShipmentSummaryDto;

    /**
     * Return pickup of units from the customer (the order's delivery address) to the seller. Database only; call
     * bookShipment() after commit.
     *
     * @param  array<int, int>  $units  order item id => units
     */
    public function createReturnPickupBooking(string $sellerOrderId, array $units, int $declaredValuePaise, string $returnReason): ShipmentSummaryDto;

    /**
     * Book a pending (or retry a failed) shipment with its courier. Never call inside a DB transaction: no locks are
     * held during the courier call. On failure the shipment stays booking_failed with the error, ready to retry.
     */
    public function bookShipment(string $shipmentId): ShipmentSummaryDto;

    /**
     * Cancel an active shipment. Reverts seller order to Packed (a cancelled reship only reports the cancellation).
     */
    public function cancelShipment(
        string $shipmentId,
        string $actorType,
        int $actorId,
        ?string $reason = null,
    ): void;

    /**
     * Poll carrier API for latest tracking status and record events.
     */
    public function syncTracking(Shipment $shipment): void;

    /**
     * Record a shipment tracking event idempotently and update shipment/order status.
     */
    public function recordShipmentEvent(Shipment $shipment, TrackingStatusDto $trackingDto): ?ShipmentEvent;

    /**
     * Fetch label PDF binary data or download path for a shipment.
     */
    public function getLabelPdf(Shipment $shipment): string;

    /**
     * Action an NDR delivery failure (reattempt or rto).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, message: string}
     */
    public function actionNdr(string $shipmentId, string $action, array $payload = []): array;

    /**
     * Advance a fake carrier shipment through simulated statuses.
     *
     * @param  array<string, mixed>  $extra
     */
    public function simulateStatusTransition(
        string $shipmentId,
        ShipmentStatus $status,
        array $extra = []
    ): ShipmentEvent;

    /**
     * Admin confirms which items were delivered on a shipment the courier marked Partially Delivered.
     * Recorded as its own partial_delivery_resolved event; it is not a courier event.
     *
     * @param  array<int>  $deliveredItemIds
     * @param  array<int>  $undeliveredItemIds
     */
    public function resolvePartialDelivery(
        string $shipmentId,
        array $deliveredItemIds,
        array $undeliveredItemIds,
        int $adminId,
        ?string $notes = null
    ): ShipmentEvent;
}
