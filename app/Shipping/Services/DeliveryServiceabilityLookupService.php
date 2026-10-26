<?php

namespace App\Shipping\Services;

use App\Shipping\Contracts\CarrierManagerInterface;
use App\Shipping\Contracts\DeliveryServiceabilityLookupInterface;
use App\Shipping\DTOs\CodValidationResultDto;
use App\Shipping\DTOs\ServiceabilityResultDto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DeliveryServiceabilityLookupService implements DeliveryServiceabilityLookupInterface
{
    public function __construct(
        protected CarrierManagerInterface $carrierManager,
    ) {}

    public function checkPincodeServiceability(string $pincode, ?string $pickupPincode = null): ServiceabilityResultDto
    {
        $cleanPincode = preg_replace('/\D/', '', $pincode);
        if (empty($cleanPincode)) {
            return new ServiceabilityResultDto(
                serviceable: false,
                codAvailable: false,
                maxCodAmountPaise: 0,
                errorMessage: 'Invalid pincode provided.',
            );
        }

        $cacheKey = "shipping_serviceability_{$cleanPincode}_".($pickupPincode ?: 'all');
        $ttlHours = (int) config('shipping.serviceability_cache_ttl_hours', 4);

        return Cache::remember($cacheKey, now()->addHours($ttlHours), function () use ($cleanPincode, $pickupPincode) {
            try {
                $driver = $this->carrierManager->driver();

                return $driver->checkServiceability($cleanPincode, $pickupPincode);
            } catch (\Throwable $e) {
                Log::warning('Carrier serviceability timeout or error (failing open)', [
                    'pincode' => $cleanPincode,
                    'error' => $e->getMessage(),
                ]);

                return new ServiceabilityResultDto(
                    serviceable: true,
                    codAvailable: true,
                    maxCodAmountPaise: 4999900,
                    errorMessage: $e->getMessage(),
                );
            }
        });
    }

    public function validatePackageCodLimits(array $packages): CodValidationResultDto
    {
        $reasons = [];
        $carrierHardLimitInr = (int) config('shipping.cod_max_limit_inr', 49999);

        foreach ($packages as $pkg) {
            $sellerName = $pkg['seller_store_name'];
            $deliveryPincode = $pkg['delivery_pincode'];
            $pickupPincode = $pkg['pickup_pincode'] ?? null;
            $codAmountPaise = $pkg['cod_amount_paise'];
            $codAmountInr = (int) ceil($codAmountPaise / 100);

            $serviceability = $this->checkPincodeServiceability($deliveryPincode, $pickupPincode);

            if (! $serviceability->serviceable) {
                $reasons[] = "Delivery is unavailable to pincode {$deliveryPincode} for package from '{$sellerName}'";

                continue;
            }

            if (! $serviceability->codAvailable) {
                $reasons[] = "Cash on Delivery is unavailable at pincode {$deliveryPincode} for package from '{$sellerName}'";

                continue;
            }

            $pincodeMaxCodInr = (int) ($serviceability->maxCodAmountPaise > 0
                ? floor($serviceability->maxCodAmountPaise / 100)
                : $carrierHardLimitInr);

            $effectiveLimitInr = min($pincodeMaxCodInr, $carrierHardLimitInr);

            if ($codAmountInr > $effectiveLimitInr) {
                $formattedAmount = number_format($codAmountInr);
                $formattedLimit = number_format($effectiveLimitInr);
                $reasons[] = "Package from '{$sellerName}' amount (₹{$formattedAmount}) exceeds maximum COD limit (₹{$formattedLimit}) for pincode {$deliveryPincode}";
            }
        }

        return new CodValidationResultDto(
            isAvailable: empty($reasons),
            failureReasons: $reasons,
        );
    }
}
