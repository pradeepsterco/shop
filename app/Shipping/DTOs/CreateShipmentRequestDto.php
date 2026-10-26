<?php

namespace App\Shipping\DTOs;

final readonly class CreateShipmentRequestDto
{
    public function __construct(
        public string $sellerOrderId,
        public int $actualWeightGrams,
        public int $lengthCm,
        public int $widthCm,
        public int $heightCm,
        public ?string $preferredDispatchDate = null,
        public bool $delayedDispatch = false,
        public ?string $pickupLocationAlias = null,
    ) {}
}
