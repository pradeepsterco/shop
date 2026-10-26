<?php

namespace App\Fees\DTOs;

use DateTimeInterface;

final readonly class FeeQuoteInput
{
    public function __construct(
        public int $pricePaise,
        public float $productGstRate,
        public ?int $verticalId = null,
        public ?int $weightGrams = null,
        public ?string $sellerPickupState = null,
        public ?string $sellerPickupPincode = null,
        public ?string $deliveryState = null,
        public ?string $deliveryPincode = null,
        public string $paymentMethod = 'cod',
        public bool $sellerHasPan = true,
        public ?DateTimeInterface $effectiveDate = null,
        public int $qty = 1,
    ) {}
}
