<?php

namespace App\Returns\DTOs;

use App\Returns\Enums\CourierClaimStatus;
use App\Returns\Enums\CourierClaimType;
use DateTimeInterface;

readonly class CourierClaimDto
{
    public function __construct(
        public string $id,
        public string $claimNumber,
        public string $shipmentId,
        public string $sellerOrderId,
        public string $carrierName,
        public CourierClaimType $claimType,
        public int $claimAmountPaise,
        public string $bearer,
        public CourierClaimStatus $status,
        public ?int $adminId = null,
        public ?string $carrierClaimReference = null,
        public ?string $notes = null,
        public ?DateTimeInterface $createdAt = null,
    ) {}
}
