<?php

namespace App\Returns\Contracts;

interface ShipmentExceptionServiceInterface
{
    /**
     * Admin confirms the courier lost the shipment. In one transaction: the seller settlement continues as
     * delivered, the seller order becomes Lost in Transit (customer is not charged), a courier claim is raised
     * for the declared value, and the platform's absorbed loss is recorded separately.
     */
    public function confirmLostShipment(
        string $shipmentId,
        string $sellerOrderId,
        string $carrierName,
        int $declaredValuePaise,
        int $adminId,
        ?string $notes = null,
    ): void;

    /**
     * Admin confirms which items of a partially delivered shipment reached the customer. In one transaction:
     * the shipment records the resolution, the settlement hold is released into the normal return window, and
     * each undelivered item is recorded for phase B missing-item handling.
     *
     * @param  array<int>  $deliveredItemIds
     * @param  array<int>  $undeliveredItemIds
     */
    public function resolvePartialDelivery(
        string $shipmentId,
        string $sellerOrderId,
        array $deliveredItemIds,
        array $undeliveredItemIds,
        int $adminId,
        ?string $notes = null,
    ): void;
}
