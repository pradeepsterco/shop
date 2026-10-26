<?php

namespace App\Sellers\DTOs;

final readonly class SellerPickupAddressDto
{
    public function __construct(
        public int $sellerProfileId,
        public string $storeName,
        public string $phone,
        public ?string $email,
        public string $addressLine1,
        public ?string $addressLine2,
        public string $city,
        public string $state,
        public string $postalCode,
        public string $country,
        public ?string $gstin,
    ) {}
}
