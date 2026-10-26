<?php

namespace App\Shipping\Contracts;

use App\Shipping\DTOs\SellerShippingProfileDto;
use App\Shipping\DTOs\ShipmentSummaryDto;

interface ShipmentLookupInterface
{
    /**
     * Get shipment summary and tracking events for a seller order.
     */
    public function getShipmentForSellerOrder(string $sellerOrderId): ?ShipmentSummaryDto;

    /**
     * Get shipment summary by shipment ULID.
     */
    public function getShipmentById(string $shipmentId): ?ShipmentSummaryDto;

    /**
     * Get seller shipping warehouse registration profile.
     */
    public function getSellerShippingProfile(int $sellerProfileId, ?string $provider = null): ?SellerShippingProfileDto;
}
