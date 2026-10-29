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
use App\Shipping\Models\Shipment;
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

    public function createReverseShipment(
        CreateShipmentRequestDto $request,
        SellerOrderShippingDetailsDto $details,
        SellerOrderInvoiceDto $invoice,
        string $returnReason,
        ?Shipment $shipment = null,
    ): CreateShipmentResponseDto {
        if ($this->shouldFailCreation) {
            return new CreateShipmentResponseDto(
                success: false,
                awb: null,
                vendorWbn: null,
                publicTrackingUrl: null,
                errorMessage: 'Simulated fake return pickup booking failure',
            );
        }

        $awb = 'SIM-RVP-'.strtoupper(Str::random(10));

        return new CreateShipmentResponseDto(success: true, awb: $awb, vendorWbn: $awb, publicTrackingUrl: null, estimatedChargesPaise: 0);
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

    public function requestNdrAction(string $awb, string $action, array $payload = []): array
    {
        $normalizedAction = strtolower(trim($action));

        if ($normalizedAction === 'rto' || $normalizedAction === 'return') {
            $this->trackingMap[$awb] = new TrackingStatusDto(
                awb: $awb,
                status: ShipmentStatus::RtoInitiated,
                providerStatus: 'RTO Initiated',
                location: 'Destination Delivery Hub',
                description: $payload['instructions'] ?? 'RTO requested by administration',
                occurredAt: now(),
                providerNdrStatus: 'RTO',
                providerEventId: 'SIM-'.Str::ulid(),
            );

            return [
                'success' => true,
                'message' => 'Simulated RTO initiated successfully.',
            ];
        }

        if ($normalizedAction === 'reattempt' || $normalizedAction === 're-attempt') {
            $dateStr = $payload['reattempt_date'] ?? 'Next business day';

            $desc = "Delivery re-attempt scheduled for {$dateStr}.";
            if (! empty($payload['phone'])) {
                $desc .= " Updated phone: {$payload['phone']}.";
            }
            if (! empty($payload['address'])) {
                $desc .= " Updated address: {$payload['address']}.";
            }

            $this->trackingMap[$awb] = new TrackingStatusDto(
                awb: $awb,
                status: ShipmentStatus::InTransit,
                providerStatus: 'In Transit - Re-Attempt Scheduled',
                location: 'Destination Hub',
                description: $desc,
                occurredAt: now(),
                providerNdrStatus: 'REATTEMPT_SCHEDULED',
                providerEventId: 'SIM-'.Str::ulid(),
            );

            return [
                'success' => true,
                'message' => 'Simulated re-attempt scheduled successfully.',
            ];
        }

        return [
            'success' => false,
            'message' => "Unsupported NDR action: {$action}",
        ];
    }

    public function simulateStatusChange(
        string $awb,
        ShipmentStatus $status,
        ?string $reason = null,
        ?string $location = null,
    ): TrackingStatusDto {
        $loc = $location ?? 'Bengaluru Central Hub';
        $desc = $reason ?? $status->label();

        $dto = new TrackingStatusDto(
            awb: $awb,
            status: $status,
            providerStatus: $status->label(),
            location: $loc,
            description: $desc,
            occurredAt: now(),
            pickedUpAt: in_array($status, [ShipmentStatus::PickedUp, ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered], true) ? now() : null,
            providerNdrStatus: $status === ShipmentStatus::DeliveryFailed ? ($reason ?? 'Delivery Attempt Failed') : null,
            providerEventId: 'SIM-'.Str::ulid(),
        );

        $this->trackingMap[$awb] = $dto;

        return $dto;
    }

    public function track(string $awb): TrackingStatusDto
    {
        return $this->trackingMap[$awb] ?? new TrackingStatusDto(
            awb: $awb,
            status: ShipmentStatus::InTransit,
            providerStatus: 'In Transit',
            location: 'Hub',
            description: 'In Transit',
        );
    }
}
