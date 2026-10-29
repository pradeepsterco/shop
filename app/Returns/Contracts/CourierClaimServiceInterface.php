<?php

namespace App\Returns\Contracts;

use App\Returns\DTOs\CourierClaimDto;

interface CourierClaimServiceInterface
{
    public function recordClaim(
        string $shipmentId,
        string $sellerOrderId,
        string $carrierName,
        string $claimType,
        int $claimAmountPaise,
        string $bearer = 'platform',
        ?int $adminId = null,
        ?string $carrierClaimReference = null,
        ?string $notes = null,
    ): CourierClaimDto;

    /**
     * Move a claim to an allowed next status (submitted -> approved|rejected, approved -> settled).
     * When $shipmentId is given, the claim must belong to that shipment.
     */
    public function updateClaimStatus(
        string $claimId,
        string $status,
        ?string $carrierClaimReference = null,
        ?string $notes = null,
        ?string $shipmentId = null,
    ): CourierClaimDto;
}
