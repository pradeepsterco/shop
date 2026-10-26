<?php

namespace App\Shipping\Services;

use App\Shipping\Contracts\ShipmentLookupInterface;
use App\Shipping\DTOs\SellerShippingProfileDto;
use App\Shipping\DTOs\ShipmentEventDto;
use App\Shipping\DTOs\ShipmentSummaryDto;
use App\Shipping\Models\SellerShippingProfile;
use App\Shipping\Models\Shipment;

class ShipmentLookupService implements ShipmentLookupInterface
{
    public function getShipmentForSellerOrder(string $sellerOrderId): ?ShipmentSummaryDto
    {
        /** @var Shipment|null $shipment */
        $shipment = Shipment::with('events')
            ->where('seller_order_id', $sellerOrderId)
            ->where('status', '!=', 'cancelled')
            ->latest()
            ->first();

        if (! $shipment) {
            /** @var Shipment|null $shipment */
            $shipment = Shipment::with('events')
                ->where('seller_order_id', $sellerOrderId)
                ->latest()
                ->first();
        }

        if (! $shipment) {
            return null;
        }

        return $this->toDto($shipment);
    }

    public function getShipmentById(string $shipmentId): ?ShipmentSummaryDto
    {
        /** @var Shipment|null $shipment */
        $shipment = Shipment::with('events')->find($shipmentId);

        if (! $shipment) {
            return null;
        }

        return $this->toDto($shipment);
    }

    public function getSellerShippingProfile(int $sellerProfileId, ?string $provider = null): ?SellerShippingProfileDto
    {
        $providerName = $provider ?: config('shipping.default', 'ekart');

        /** @var SellerShippingProfile|null $profile */
        $profile = SellerShippingProfile::where('seller_profile_id', $sellerProfileId)
            ->where('provider', $providerName)
            ->first();

        if (! $profile) {
            return null;
        }

        return new SellerShippingProfileDto(
            sellerProfileId: (int) $profile->seller_profile_id,
            provider: (string) $profile->provider,
            warehouseAlias: (string) $profile->warehouse_alias,
            isRegistered: (bool) $profile->is_registered,
            registrationStatus: (string) $profile->registration_status,
            lastRegisteredAt: $profile->last_registered_at,
            errorMessage: $profile->error_message,
            pincode: $profile->pincode ? (int) $profile->pincode : null,
        );
    }

    private function toDto(Shipment $shipment): ShipmentSummaryDto
    {
        $events = [];
        foreach ($shipment->events as $e) {
            $events[] = new ShipmentEventDto(
                id: (string) $e->id,
                eventKey: (string) $e->event_key,
                provider: (string) $e->provider,
                status: $e->status,
                providerStatus: (string) $e->provider_status,
                providerNdrStatus: $e->provider_ndr_status,
                location: $e->location,
                description: $e->description,
                occurredAt: $e->occurred_at,
                receivedAt: $e->received_at,
            );
        }

        return new ShipmentSummaryDto(
            id: (string) $shipment->id,
            sellerOrderId: (string) $shipment->seller_order_id,
            orderId: (string) $shipment->order_id,
            sellerProfileId: (int) $shipment->seller_profile_id,
            provider: (string) $shipment->provider,
            awb: $shipment->awb,
            vendorWbn: $shipment->vendor_wbn,
            publicTrackingUrl: $shipment->public_tracking_url,
            status: $shipment->status,
            actualWeightGrams: (int) $shipment->actual_weight_grams,
            volumetricWeightGrams: (int) $shipment->volumetric_weight_grams,
            chargeableWeightGrams: (int) $shipment->chargeable_weight_grams,
            lengthCm: (int) $shipment->length_cm,
            widthCm: (int) $shipment->width_cm,
            heightCm: (int) $shipment->height_cm,
            isCod: (bool) $shipment->is_cod,
            codAmountPaise: (int) $shipment->cod_amount_paise,
            labelPath: $shipment->label_path,
            labelGeneratedAt: $shipment->label_generated_at,
            pickupDate: $shipment->pickup_date,
            pickedUpAt: $shipment->picked_up_at,
            deliveredAt: $shipment->delivered_at,
            cancelledAt: $shipment->cancelled_at,
            events: $events,
        );
    }
}
