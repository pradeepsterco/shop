<?php

namespace App\Shipping\Drivers;

use App\Orders\DTOs\SellerOrderInvoiceDto;
use App\Orders\DTOs\SellerOrderShippingDetailsDto;
use App\Sellers\DTOs\SellerPickupAddressDto;
use App\Shipping\Contracts\ShippingCarrierInterface;
use App\Shipping\DTOs\CreateShipmentRequestDto;
use App\Shipping\DTOs\CreateShipmentResponseDto;
use App\Shipping\DTOs\SellerWarehouseRegistrationDto;
use App\Shipping\DTOs\ServiceabilityResultDto;
use App\Shipping\DTOs\TrackingStatusDto;
use App\Shipping\Enums\ShipmentStatus;
use Illuminate\Support\Str;

class ManualCarrierDriver implements ShippingCarrierInterface
{
    public function name(): string
    {
        return 'manual';
    }

    public function checkServiceability(string $pincode, ?string $pickupPincode = null): ServiceabilityResultDto
    {
        return new ServiceabilityResultDto(
            serviceable: true,
            codAvailable: true,
            maxCodAmountPaise: 4999900,
            city: 'Manual Delivery Zone',
            state: 'India',
            tatMinDays: 2,
            tatMaxDays: 7,
        );
    }

    public function registerPickupAddress(SellerPickupAddressDto $address): SellerWarehouseRegistrationDto
    {
        $alias = 'MAN-WH-'.$address->sellerProfileId;

        return new SellerWarehouseRegistrationDto(
            success: true,
            alias: $alias,
            message: 'Manual warehouse registered',
        );
    }

    public function getRegisteredAddresses(): array
    {
        return [];
    }

    public function createShipment(
        CreateShipmentRequestDto $request,
        SellerOrderShippingDetailsDto $details,
        SellerOrderInvoiceDto $invoice,
    ): CreateShipmentResponseDto {
        $awb = 'MAN-'.strtoupper(Str::random(10));

        return new CreateShipmentResponseDto(
            success: true,
            awb: $awb,
            vendorWbn: $awb,
            publicTrackingUrl: null,
            estimatedChargesPaise: 0,
        );
    }

    public function downloadLabel(string $awb): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 300 400]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000109 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n185\n%%EOF";
    }

    public function setDispatchDate(array $awbs, string $date): bool
    {
        return true;
    }

    public function cancelShipment(string $awb): bool
    {
        return true;
    }

    public function track(string $awb): TrackingStatusDto
    {
        return new TrackingStatusDto(
            awb: $awb,
            status: ShipmentStatus::InTransit,
            providerStatus: 'Manual In Transit',
            location: 'Local Hub',
            description: 'Package in manual transit',
            occurredAt: now(),
        );
    }
}
