<?php

namespace App\Shipping\DTOs;

use DateTimeInterface;

final readonly class SellerShippingProfileDto
{
    public function __construct(
        public int $sellerProfileId,
        public string $provider,
        public string $warehouseAlias,
        public bool $isRegistered,
        public string $registrationStatus,
        public ?DateTimeInterface $lastRegisteredAt = null,
        public ?string $errorMessage = null,
        public ?int $pincode = null,
    ) {}
}
