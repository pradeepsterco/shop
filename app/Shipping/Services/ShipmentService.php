<?php

namespace App\Shipping\Services;

use App\Orders\Contracts\SellerOrderFulfillmentInterface;
use App\Orders\Contracts\SellerOrderShippingDetailsLookupInterface;
use App\Sellers\Contracts\SellerPickupAddressLookupInterface;
use App\Shipping\Contracts\CarrierManagerInterface;
use App\Shipping\Contracts\ShipmentServiceInterface;
use App\Shipping\Drivers\ShiprocketCarrierDriver;
use App\Shipping\DTOs\CreateShipmentRequestDto;
use App\Shipping\DTOs\TrackingStatusDto;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\SellerShippingProfile;
use App\Shipping\Models\Shipment;
use App\Shipping\Models\ShipmentEvent;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ShipmentService implements ShipmentServiceInterface
{
    public function __construct(
        protected CarrierManagerInterface $carrierManager,
        protected SellerOrderFulfillmentInterface $orderFulfillment,
        protected SellerOrderShippingDetailsLookupInterface $shippingDetailsLookup,
        protected SellerPickupAddressLookupInterface $pickupAddressLookup,
    ) {}

    public function createShipmentForSellerOrder(
        string $sellerOrderId,
        int $actualWeightGrams,
        int $lengthCm,
        int $widthCm,
        int $heightCm,
        ?string $pickupDate = null,
    ): Shipment {
        // Idempotency: check if active non-cancelled shipment already exists
        /** @var Shipment|null $existing */
        $existing = Shipment::where('seller_order_id', $sellerOrderId)
            ->where('status', '!=', ShipmentStatus::Cancelled->value)
            ->first();

        if ($existing && $existing->creation_step === 'label_ready') {
            return $existing;
        }

        $details = $this->shippingDetailsLookup->getShippingDetails($sellerOrderId);
        if (! $details) {
            throw new DomainException("Order details not found for seller order {$sellerOrderId}.");
        }

        $driver = $this->carrierManager->driver();

        // Check seller warehouse registration
        $this->ensureSellerWarehouseRegistered($details->sellerProfileId, $driver->name());

        // Calculate volumetric and chargeable weights
        $divisor = (int) config('shipping.volumetric_divisor', 5000);
        $volumetricWeightGrams = (int) round((($lengthCm * $widthCm * $heightCm) / $divisor) * 1000);
        $chargeableWeightGrams = max($actualWeightGrams, $volumetricWeightGrams);

        // Generate GST Invoice Number
        $invoice = $this->orderFulfillment->getOrCreateInvoiceNumber($sellerOrderId);

        $requestDto = new CreateShipmentRequestDto(
            sellerOrderId: $sellerOrderId,
            actualWeightGrams: $actualWeightGrams,
            lengthCm: $lengthCm,
            widthCm: $widthCm,
            heightCm: $heightCm,
            preferredDispatchDate: $pickupDate,
            pickupLocationAlias: 'WH-'.$details->sellerProfileId,
        );

        $previousAttempts = Shipment::where('seller_order_id', $sellerOrderId)->count();
        $attempt = $existing ? ($existing->fulfillment_attempt ?: 1) : ($previousAttempts + 1);

        $targetShipment = $existing;
        if (! $targetShipment) {
            /** @var Shipment $targetShipment */
            $targetShipment = Shipment::create([
                'seller_order_id' => $details->sellerOrderId,
                'order_id' => $details->orderId,
                'seller_profile_id' => $details->sellerProfileId,
                'provider' => $driver->name(),
                'fulfillment_attempt' => $attempt,
                'creation_step' => 'pending',
                'status' => ShipmentStatus::Created,
                'actual_weight_grams' => $actualWeightGrams,
                'volumetric_weight_grams' => $volumetricWeightGrams,
                'chargeable_weight_grams' => $chargeableWeightGrams,
                'length_cm' => $lengthCm,
                'width_cm' => $widthCm,
                'height_cm' => $heightCm,
                'is_cod' => $details->isCod,
                'cod_amount_paise' => $details->codAmountPaise,
                'pickup_date' => $pickupDate,
            ]);
        }

        $response = $driver instanceof ShiprocketCarrierDriver
            ? $driver->createShipment($requestDto, $details, $invoice, $targetShipment)
            : $driver->createShipment($requestDto, $details, $invoice);
        if (! $response->success || empty($response->awb)) {
            throw new DomainException('Carrier shipment manifestation failed: '.($response->errorMessage ?? 'Unknown error'));
        }

        return DB::transaction(function () use (
            $details,
            $driver,
            $response,
            $targetShipment,
            $actualWeightGrams,
            $volumetricWeightGrams,
            $chargeableWeightGrams,
            $lengthCm,
            $widthCm,
            $heightCm,
            $pickupDate,
            $attempt
        ) {
            $rawMeta = $response->rawResponse ?? [];
            $courierName = $rawMeta['courier_name'] ?? null;
            $provOrderId = isset($rawMeta['shiprocket_order_id']) ? (string) $rawMeta['shiprocket_order_id'] : null;
            $provShipmentId = isset($rawMeta['shiprocket_shipment_id']) ? (string) $rawMeta['shiprocket_shipment_id'] : null;

            $targetShipment->update([
                'courier_name' => $courierName,
                'awb' => $response->awb,
                'vendor_wbn' => $response->vendorWbn,
                'provider_order_id' => $provOrderId,
                'provider_shipment_id' => $provShipmentId,
                'fulfillment_attempt' => $attempt,
                'creation_step' => 'label_ready',
                'public_tracking_url' => $response->publicTrackingUrl,
                'status' => ShipmentStatus::Created,
                'actual_weight_grams' => $actualWeightGrams,
                'volumetric_weight_grams' => $volumetricWeightGrams,
                'chargeable_weight_grams' => $chargeableWeightGrams,
                'length_cm' => $lengthCm,
                'width_cm' => $widthCm,
                'height_cm' => $heightCm,
                'is_cod' => $details->isCod,
                'cod_amount_paise' => $details->codAmountPaise,
                'pickup_date' => $pickupDate,
                'estimated_carrier_charges_paise' => $response->estimatedChargesPaise,
                'raw_metadata' => $response->rawResponse,
            ]);

            // Attempt to fetch label PDF if not already saved
            if (! $targetShipment->label_path || ! Storage::disk('local')->exists($targetShipment->label_path)) {
                try {
                    $labelBinary = $driver->downloadLabel($targetShipment->awb);
                    $labelPath = "shipping_labels/{$targetShipment->id}.pdf";
                    Storage::disk('local')->put($labelPath, $labelBinary);
                    $targetShipment->update([
                        'label_path' => $labelPath,
                        'label_generated_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Initial label download deferred', [
                        'shipment_id' => $targetShipment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Create initial event if not created
            $eventKey = "{$targetShipment->id}:created:".time();
            if (! ShipmentEvent::where('shipment_id', $targetShipment->id)->where('status', ShipmentStatus::Created)->exists()) {
                ShipmentEvent::create([
                    'shipment_id' => $targetShipment->id,
                    'event_key' => $eventKey,
                    'provider' => $driver->name(),
                    'status' => ShipmentStatus::Created,
                    'provider_status' => 'Created',
                    'description' => 'Shipment package created with carrier',
                    'occurred_at' => now(),
                    'received_at' => now(),
                ]);
            }

            // Mark seller order as Shipped in Orders module
            $this->orderFulfillment->markShipped(
                sellerOrderId: $details->sellerOrderId,
                courierName: $driver->name(),
                trackingNumber: $response->awb,
            );

            return $targetShipment;
        });
    }

    public function cancelShipment(
        string $shipmentId,
        string $actorType,
        int $actorId,
        ?string $reason = null,
    ): void {
        /** @var Shipment $shipment */
        $shipment = Shipment::findOrFail($shipmentId);

        if ($shipment->status === ShipmentStatus::Cancelled) {
            return;
        }

        if ($shipment->status === ShipmentStatus::Delivered || $shipment->status === ShipmentStatus::RtoDelivered) {
            throw new DomainException('Cannot cancel a shipment that has already been delivered.');
        }

        $driver = $this->carrierManager->driver($shipment->provider);
        if ($shipment->awb) {
            $driver->cancelShipment($shipment->awb);
        }

        DB::transaction(function () use ($shipment, $reason) {
            $shipment->update([
                'status' => ShipmentStatus::Cancelled,
                'cancelled_at' => now(),
            ]);

            ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'event_key' => "{$shipment->id}:cancelled:".time(),
                'provider' => $shipment->provider,
                'status' => ShipmentStatus::Cancelled,
                'provider_status' => 'Cancelled',
                'description' => $reason ?? 'Shipment cancelled',
                'occurred_at' => now(),
                'received_at' => now(),
            ]);

            // Revert seller order to Packed (shipment cancellation only cancels shipment)
            $this->orderFulfillment->revertToPacked(
                sellerOrderId: $shipment->seller_order_id,
                reason: $reason ?? 'Shipment cancelled',
            );
        });
    }

    public function syncTracking(Shipment $shipment): void
    {
        if ($shipment->status->isTerminal() || empty($shipment->awb)) {
            return;
        }

        $driver = $this->carrierManager->driver($shipment->provider);
        $trackingDto = $driver->track($shipment->awb);

        $this->recordShipmentEvent($shipment, $trackingDto);

        $shipment->update(['last_polled_at' => now()]);
    }

    public function recordShipmentEvent(Shipment $shipment, TrackingStatusDto $trackingDto): ?ShipmentEvent
    {
        $occurredTimestamp = $trackingDto->occurredAt ? $trackingDto->occurredAt->getTimestamp() : time();
        $eventKey = "{$shipment->id}:{$trackingDto->status->value}:{$occurredTimestamp}";

        // Idempotency deduplication by event_key
        $existing = ShipmentEvent::where('event_key', $eventKey)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($shipment, $trackingDto, $eventKey) {
            /** @var ShipmentEvent $event */
            $event = ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'event_key' => $eventKey,
                'provider' => $shipment->provider,
                'status' => $trackingDto->status,
                'provider_status' => $trackingDto->providerStatus,
                'provider_ndr_status' => $trackingDto->providerNdrStatus,
                'location' => $trackingDto->location,
                'description' => $trackingDto->description,
                'occurred_at' => $trackingDto->occurredAt,
                'received_at' => now(),
                'raw_payload' => $trackingDto->rawResponse,
            ]);

            // Update shipment state
            $updates = [
                'status' => $trackingDto->status,
            ];

            if ($trackingDto->pickedUpAt && ! $shipment->picked_up_at) {
                $updates['picked_up_at'] = $trackingDto->pickedUpAt;
            }

            if ($trackingDto->status === ShipmentStatus::Delivered && ! $shipment->delivered_at) {
                $deliveredAt = $trackingDto->occurredAt ? Carbon::instance($trackingDto->occurredAt) : now();
                $updates['delivered_at'] = $deliveredAt;

                $this->orderFulfillment->markDelivered(
                    sellerOrderId: $shipment->seller_order_id,
                    deliveredAt: $deliveredAt,
                );
            }

            if ($trackingDto->status === ShipmentStatus::RtoDelivered) {
                // RTO delivered has no settlement modification in this phase
                $this->orderFulfillment->markReturnedToOrigin(
                    sellerOrderId: $shipment->seller_order_id,
                    reason: $trackingDto->description ?? 'RTO Delivered to seller',
                );
            }

            $shipment->update($updates);

            return $event;
        });
    }

    public function getLabelPdf(Shipment $shipment): string
    {
        if ($shipment->label_path && Storage::disk('local')->exists($shipment->label_path)) {
            return (string) Storage::disk('local')->get($shipment->label_path);
        }

        $driver = $this->carrierManager->driver($shipment->provider);
        $binary = $driver->downloadLabel($shipment->awb);

        $path = "shipping_labels/{$shipment->id}.pdf";
        Storage::disk('local')->put($path, $binary);

        $shipment->update([
            'label_path' => $path,
            'label_generated_at' => now(),
        ]);

        return $binary;
    }

    /**
     * Ensure seller warehouse is registered with provider before shipping.
     */
    protected function ensureSellerWarehouseRegistered(int $sellerProfileId, string $provider): void
    {
        if ($provider === 'manual' || $provider === 'fake') {
            return;
        }

        /** @var SellerShippingProfile|null $profile */
        $profile = SellerShippingProfile::where('seller_profile_id', $sellerProfileId)
            ->where('provider', $provider)
            ->first();

        if ($profile && $profile->registration_status === 'pending_verification') {
            throw new DomainException("Pickup address for seller ID {$sellerProfileId} is pending phone/OTP verification with Shiprocket. Ready to ship is blocked.");
        }

        if ($profile && $profile->is_registered && $profile->registration_status === 'registered') {
            return;
        }

        $address = $this->pickupAddressLookup->getSellerPickupAddress($sellerProfileId);
        if (! $address) {
            throw new DomainException("Pickup address not found for seller profile ID {$sellerProfileId}.");
        }

        $driver = $this->carrierManager->driver($provider);
        $res = $driver->registerPickupAddress($address);

        $alias = $res->alias ?: ('WH-'.$sellerProfileId);
        $pincode = (int) preg_replace('/\D/', '', $address->postalCode);
        $statusText = $res->rawResponse['status_text'] ?? ($res->success ? 'registered' : 'failed');
        $phoneVerified = (bool) ($res->rawResponse['phone_verified'] ?? ($res->success && $statusText === 'registered'));

        SellerShippingProfile::updateOrCreate(
            ['seller_profile_id' => $sellerProfileId, 'provider' => $provider],
            [
                'warehouse_alias' => $alias,
                'is_registered' => $res->success,
                'registration_status' => $statusText,
                'phone_verified' => $phoneVerified,
                'last_registered_at' => now(),
                'error_message' => $res->success ? null : $res->message,
                'pincode' => $pincode,
            ]
        );

        if ($statusText === 'pending_verification') {
            throw new DomainException("Pickup address for seller ID {$sellerProfileId} is pending phone/OTP verification with Shiprocket. Ready to ship is blocked.");
        }

        if (! $res->success) {
            throw new DomainException("Pickup address for seller '{$address->storeName}' is not registered with carrier: ".($res->message ?? 'Registration pending'));
        }
    }
}
