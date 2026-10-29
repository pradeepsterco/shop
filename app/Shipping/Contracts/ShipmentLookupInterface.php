<?php

namespace App\Shipping\Contracts;

use App\Shipping\DTOs\SellerShippingProfileDto;
use App\Shipping\DTOs\ShipmentSummaryDto;
use App\Shipping\Enums\ShipmentDirection;

interface ShipmentLookupInterface
{
    /**
     * The package's own delivery: the latest original forward shipment of the seller order
     * (reships, return pickups and replacements are not returned here).
     */
    public function getShipmentForSellerOrder(string $sellerOrderId): ?ShipmentSummaryDto;

    /**
     * Every shipment of a seller order (original, reships, return pickups), oldest first,
     * optionally only one direction.
     *
     * @return list<ShipmentSummaryDto>
     */
    public function getShipmentsForSellerOrder(string $sellerOrderId, ?ShipmentDirection $direction = null): array;

    /**
     * Get shipment summary by shipment ULID.
     */
    public function getShipmentById(string $shipmentId): ?ShipmentSummaryDto;

    /**
     * Get seller shipping warehouse registration profile.
     */
    public function getSellerShippingProfile(int $sellerProfileId, ?string $provider = null): ?SellerShippingProfileDto;
}
