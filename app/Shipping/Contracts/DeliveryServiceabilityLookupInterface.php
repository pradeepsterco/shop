<?php

namespace App\Shipping\Contracts;

use App\Shipping\DTOs\CodValidationResultDto;
use App\Shipping\DTOs\ServiceabilityResultDto;

interface DeliveryServiceabilityLookupInterface
{
    /**
     * Check delivery serviceability and COD limit for a destination pincode.
     */
    public function checkPincodeServiceability(string $pincode, ?string $pickupPincode = null): ServiceabilityResultDto;

    /**
     * Validate COD availability at the package level for all packages in a cart/checkout.
     * Each package element must specify:
     * - 'seller_profile_id' (int)
     * - 'seller_store_name' (string)
     * - 'cod_amount_paise' (int)
     * - 'delivery_pincode' (string)
     * - 'pickup_pincode' (string|null)
     *
     * @param  list<array{seller_profile_id: int, seller_store_name: string, cod_amount_paise: int, delivery_pincode: string, pickup_pincode?: string|null}>  $packages
     */
    public function validatePackageCodLimits(array $packages): CodValidationResultDto;
}
