<?php

namespace App\Sellers\Services;

use App\Enums\SellerStatus;
use App\Models\SellerProfile;
use App\Sellers\Contracts\SellerPickupAddressLookupInterface;
use App\Sellers\DTOs\SellerPickupAddressDto;
use Illuminate\Support\Collection;

class SellerPickupAddressLookupService implements SellerPickupAddressLookupInterface
{
    public function getSellerPickupAddress(int $sellerProfileId): ?SellerPickupAddressDto
    {
        /** @var SellerProfile|null $profile */
        $profile = SellerProfile::with('user')->find($sellerProfileId);

        if (! $profile) {
            return null;
        }

        return $this->toDto($profile);
    }

    /**
     * @return Collection<int, SellerPickupAddressDto>
     */
    public function getAllActiveSellerPickupAddresses(): Collection
    {
        return SellerProfile::with('user')
            ->where('status', SellerStatus::Approved)
            ->get()
            ->map(fn (SellerProfile $profile) => $this->toDto($profile));
    }

    private function toDto(SellerProfile $profile): SellerPickupAddressDto
    {
        return new SellerPickupAddressDto(
            sellerProfileId: (int) $profile->id,
            storeName: (string) $profile->store_name,
            phone: (string) ($profile->support_phone ?: $profile->user->phone),
            email: $profile->support_email ?: $profile->user->email,
            addressLine1: (string) ($profile->pickup_address_line1 ?: 'Pickup Address'),
            addressLine2: $profile->pickup_address_line2,
            city: (string) ($profile->pickup_city ?: 'City'),
            state: (string) ($profile->pickup_state ?: 'State'),
            postalCode: (string) ($profile->pickup_postal_code ?: '000000'),
            country: (string) ($profile->pickup_country ?: 'India'),
            gstin: $profile->gstin,
        );
    }
}
