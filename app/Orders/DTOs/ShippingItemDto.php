<?php

namespace App\Orders\DTOs;

final readonly class ShippingItemDto
{
    public function __construct(
        public int $orderItemId,
        public ?int $listingId,
        public ?int $variantId,
        public string $title,
        public ?string $sku,
        public int $quantity,
        public int $unitPricePaise,
        public int $taxRatePercent,
        public int $taxAmountPaise,
        public int $weightGrams,
        public int $lengthCm,
        public int $widthCm,
        public int $heightCm,
    ) {}
}
