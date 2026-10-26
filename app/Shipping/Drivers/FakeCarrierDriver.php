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

class FakeCarrierDriver implements ShippingCarrierInterface
{
    /** @var array<string, TrackingStatusDto> */
    public array $trackingMap = [];

    /** @var array<string, ServiceabilityResultDto> */
    public array $serviceabilityMap = [];

    public bool $shouldFailCreation = false;

    public bool $shouldFailLabel = false;

    public function name(): string
    {
        return 'fake';
    }

    public function checkServiceability(string $pincode, ?string $pickupPincode = null): ServiceabilityResultDto
    {
        if (isset($this->serviceabilityMap[$pincode])) {
            return $this->serviceabilityMap[$pincode];
        }

        return new ServiceabilityResultDto(
            serviceable: true,
            codAvailable: true,
            maxCodAmountPaise: 4999900,
            city: 'Bengaluru',
            state: 'Karnataka',
            tatMinDays: 2,
            tatMaxDays: 4,
        );
    }

    public function registerPickupAddress(SellerPickupAddressDto $address): SellerWarehouseRegistrationDto
    {
        $alias = 'FAKE-WH-'.$address->sellerProfileId;

        return new SellerWarehouseRegistrationDto(
            success: true,
            alias: $alias,
            message: 'Fake warehouse registered successfully',
        );
    }

    public function getRegisteredAddresses(): array
    {
        return [
            [
                'alias' => 'FAKE-WH-1',
                'phone' => 9876543210,
                'address_line1' => 'Fake Warehouse Road',
                'pincode' => 560001,
                'city' => 'Bengaluru',
                'state' => 'Karnataka',
                'country' => 'India',
            ],
        ];
    }

    public function createShipment(
        CreateShipmentRequestDto $request,
        SellerOrderShippingDetailsDto $details,
        SellerOrderInvoiceDto $invoice,
    ): CreateShipmentResponseDto {
        if ($this->shouldFailCreation) {
            return new CreateShipmentResponseDto(
                success: false,
                awb: null,
                vendorWbn: null,
                publicTrackingUrl: null,
                errorMessage: 'Simulated fake creation failure',
            );
        }

        $awb = 'FAKE-'.strtoupper(Str::random(10));

        $this->trackingMap[$awb] = new TrackingStatusDto(
            awb: $awb,
            status: ShipmentStatus::Created,
            providerStatus: 'Order Placed',
            location: 'Bengaluru Hub',
            description: 'Shipment created successfully',
            occurredAt: now(),
        );

        return new CreateShipmentResponseDto(
            success: true,
            awb: $awb,
            vendorWbn: $awb,
            publicTrackingUrl: "https://fakecarrier.test/track/{$awb}",
            estimatedChargesPaise: 12000,
        );
    }

    public function downloadLabel(string $awb): string
    {
        if ($this->shouldFailLabel) {
            throw new \DomainException('Fake label download failure');
        }

        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 300 400]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000109 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n185\n%%EOF";
    }

    public function setDispatchDate(array $awbs, string $date): bool
    {
        return true;
    }

    public function cancelShipment(string $awb): bool
    {
        if (isset($this->trackingMap[$awb])) {
            $this->trackingMap[$awb] = new TrackingStatusDto(
                awb: $awb,
                status: ShipmentStatus::Cancelled,
                providerStatus: 'Cancelled',
                location: 'Hub',
                description: 'Shipment cancelled',
                occurredAt: now(),
            );
        }

        return true;
    }

    public function track(string $awb): TrackingStatusDto
    {
        return $this->trackingMap[$awb] ?? new TrackingStatusDto(
            awb: $awb,
            status: ShipmentStatus::InTransit,
            providerStatus: 'In Transit',
            location: 'Hub',
            description: 'In Transit',
            occurredAt: now(),
        );
    }
}
