<?php

namespace App\Returns\Services;

use App\Returns\Contracts\CourierClaimServiceInterface;
use App\Returns\DTOs\CourierClaimDto;
use App\Returns\Enums\CourierClaimStatus;
use App\Returns\Enums\CourierClaimType;
use App\Returns\Models\CourierClaim;
use DomainException;
use Illuminate\Support\Str;

class CourierClaimService implements CourierClaimServiceInterface
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
    ): CourierClaimDto {
        $typeEnum = CourierClaimType::tryFrom($claimType);
        if (! $typeEnum) {
            throw new DomainException("Invalid claim type: {$claimType}");
        }

        $claimNumber = 'CLM-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

        /** @var CourierClaim $claim */
        $claim = CourierClaim::create([
            'claim_number' => $claimNumber,
            'shipment_id' => $shipmentId,
            'seller_order_id' => $sellerOrderId,
            'carrier_name' => $carrierName,
            'claim_type' => $typeEnum,
            'claim_amount_paise' => $claimAmountPaise,
            'bearer' => $bearer,
            'status' => CourierClaimStatus::Submitted,
            'admin_id' => $adminId,
            'carrier_claim_reference' => $carrierClaimReference,
            'notes' => $notes,
        ]);

        return $this->toDto($claim);
    }

    public function updateClaimStatus(
        string $claimId,
        string $status,
        ?string $carrierClaimReference = null,
        ?string $notes = null,
        ?string $shipmentId = null,
    ): CourierClaimDto {
        $statusEnum = CourierClaimStatus::tryFrom($status);
        if (! $statusEnum) {
            throw new DomainException("Invalid claim status: {$status}");
        }

        /** @var CourierClaim $claim */
        $claim = CourierClaim::findOrFail($claimId);

        if ($shipmentId !== null && $claim->shipment_id !== $shipmentId) {
            throw new DomainException('This claim does not belong to the shipment.');
        }

        if (! in_array($statusEnum, $claim->status->nextStatuses(), true)) {
            throw new DomainException("A {$claim->status->value} claim cannot be marked {$statusEnum->value}.");
        }

        $updates = ['status' => $statusEnum];
        if ($carrierClaimReference !== null) {
            $updates['carrier_claim_reference'] = $carrierClaimReference;
        }
        if ($notes !== null) {
            $updates['notes'] = $notes;
        }

        $claim->update($updates);

        return $this->toDto($claim);
    }

    protected function toDto(CourierClaim $claim): CourierClaimDto
    {
        return new CourierClaimDto(
            id: $claim->id,
            claimNumber: $claim->claim_number,
            shipmentId: $claim->shipment_id,
            sellerOrderId: $claim->seller_order_id,
            carrierName: $claim->carrier_name,
            claimType: $claim->claim_type,
            claimAmountPaise: (int) $claim->claim_amount_paise,
            bearer: $claim->bearer,
            status: $claim->status,
            adminId: $claim->admin_id ? (int) $claim->admin_id : null,
            carrierClaimReference: $claim->carrier_claim_reference,
            notes: $claim->notes,
            createdAt: $claim->created_at,
        );
    }
}
