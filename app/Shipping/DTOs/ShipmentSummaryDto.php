<?php

namespace App\Shipping\DTOs;

use App\Shipping\Enums\ShipmentStatus;
use DateTimeInterface;

final readonly class ShipmentSummaryDto
{
    /**
     * @param  list<ShipmentEventDto>  $events
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
        public array $events = [],
    ) {}
}
