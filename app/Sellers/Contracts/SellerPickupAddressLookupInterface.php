<?php

namespace App\Sellers\Contracts;

use App\Sellers\DTOs\SellerPickupAddressDto;
use Illuminate\Support\Collection;

interface SellerPickupAddressLookupInterface
{
    /**
     * Get seller pickup address details by seller profile id.
     */
    public function getSellerPickupAddress(int $sellerProfileId): ?SellerPickupAddressDto;

    /**
     * Get all approved sellers' pickup addresses for bulk warehouse sync.
     *
     * @return Collection<int, SellerPickupAddressDto>
     */
    public function getAllActiveSellerPickupAddresses(): Collection;
}
