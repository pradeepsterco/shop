<?php

namespace App\Shipping\DTOs;

use App\Shipping\Enums\ShipmentDirection;
use App\Shipping\Enums\ShipmentPurpose;
use App\Shipping\Enums\ShipmentStatus;
use DateTimeInterface;

final readonly class ShipmentSummaryDto
{
    /**
     * @param  array<string, mixed>|null  $partialDeliveryData
     * @param  list<ShipmentEventDto>  $events
     * @param  array<int, int>  $items  order item id => units carried; empty = whole seller order
     */
    public function __construct(
        public string $id,
        public string $sellerOrderId,
        public string $orderId,
        public int $sellerProfileId,
        public string $provider,
        public ?string $awb,
        public ?string $vendorWbn,
        public ?string $publicTrackingUrl,
        public ShipmentStatus $status,
        public int $actualWeightGrams,
        public int $volumetricWeightGrams,
        public int $chargeableWeightGrams,
        public int $lengthCm,
        public int $widthCm,
        public int $heightCm,
        public bool $isCod,
        public int $codAmountPaise,
        public ?string $labelPath = null,
        public ?DateTimeInterface $labelGeneratedAt = null,
        public ?DateTimeInterface $pickupDate = null,
        public ?DateTimeInterface $pickedUpAt = null,
        public ?DateTimeInterface $deliveredAt = null,
        public ?DateTimeInterface $cancelledAt = null,
        public int $ndrAttemptCount = 0,
        public ?string $latestNdrReason = null,
        public ?string $ndrUpdatedPhone = null,
        public ?string $ndrUpdatedAddress = null,
        public ?string $ndrAction = null,
        public ?array $partialDeliveryData = null,
        public array $events = [],
        public ShipmentDirection $direction = ShipmentDirection::Forward,
        public ShipmentPurpose $purpose = ShipmentPurpose::Original,
        public ?string $parentShipmentId = null,
        public array $items = [],
    ) {}

    public function statusLabel(): string
    {
        return $this->status->labelFor($this->direction);
    }
}
