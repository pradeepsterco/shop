<?php

namespace App\Returns\Contracts;

use App\Returns\DTOs\CourierClaimDto;
use App\Returns\DTOs\RtoIntakeReportDto;

interface ReturnsLookupInterface
{
    public function getRtoReportForSellerOrder(string $sellerOrderId): ?RtoIntakeReportDto;

    public function getRtoReportForShipment(string $shipmentId): ?RtoIntakeReportDto;

    /**
     * @return array<int, CourierClaimDto>
     */
    public function getClaimsForShipment(string $shipmentId): array;

    /**
     * @return array<int, CourierClaimDto>
     */
    public function getClaimsForSellerOrder(string $sellerOrderId): array;

    /**
     * Check if the seller RTO inspection deadline has passed.
     */
    public function isRtoInspectionDeadlinePassed(?string $rtoDeliveredAt): bool;
}
