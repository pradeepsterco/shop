<?php

namespace App\Shipping\Services;

use App\Orders\Contracts\SellerOrderFulfillmentInterface;
use App\Orders\Contracts\SellerOrderShippingDetailsLookupInterface;
use App\Sellers\Contracts\SellerPickupAddressLookupInterface;
use App\Shipping\Contracts\CarrierManagerInterface;
use App\Shipping\Contracts\ShipmentLookupInterface;
use App\Shipping\Contracts\ShipmentServiceInterface;
use App\Shipping\Drivers\FakeCarrierDriver;
use App\Shipping\Drivers\ShiprocketCarrierDriver;
use App\Shipping\DTOs\CreateShipmentRequestDto;
use App\Shipping\DTOs\CreateShipmentResponseDto;
use App\Shipping\DTOs\ShipmentSummaryDto;
use App\Shipping\DTOs\TrackingStatusDto;
use App\Shipping\Enums\ShipmentDirection;
use App\Shipping\Enums\ShipmentEventType;
use App\Shipping\Enums\ShipmentPurpose;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Events\ShipmentDeliveryFailed;
use App\Shipping\Events\ShipmentLost;
use App\Shipping\Events\ShipmentPartialDelivered;
use App\Shipping\Events\ShipmentReshipCancelled;
use App\Shipping\Events\ShipmentReshipDelivered;
use App\Shipping\Events\ShipmentReshipReturned;
use App\Shipping\Events\ShipmentReturnLost;
use App\Shipping\Events\ShipmentReturnPickupCancelled;
use App\Shipping\Events\ShipmentReturnPickupFailed;
use App\Shipping\Events\ShipmentReturnReceived;
use App\Shipping\Events\ShipmentRtoDelivered;
use App\Shipping\Events\ShipmentRtoInitiated;
use App\Shipping\Models\SellerShippingProfile;
use App\Shipping\Models\Shipment;
use App\Shipping\Models\ShipmentEvent;
use App\Shipping\Models\ShipmentItem;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            ->where('direction', ShipmentDirection::Forward->value)
            ->where('purpose', ShipmentPurpose::Original->value)
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

        $previousAttempts = Shipment::where('seller_order_id', $sellerOrderId)
            ->where('direction', ShipmentDirection::Forward->value)
            ->where('purpose', ShipmentPurpose::Original->value)
            ->count();
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
                'declared_value_paise' => $details->grandTotalPaise,
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
                'declared_value_paise' => $details->grandTotalPaise,
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

            // Create initial event if not created (once per shipment, so the key needs no timestamp)
            if (! ShipmentEvent::where('shipment_id', $targetShipment->id)->where('status', ShipmentStatus::Created)->exists()) {
                ShipmentEvent::create([
                    'shipment_id' => $targetShipment->id,
                    'event_key' => "{$targetShipment->id}:created",
                    'event_type' => ShipmentEventType::ShipmentCreated,
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

    public function createReshipBooking(string $sellerOrderId, array $units, int $codAmountPaise, int $declaredValuePaise): ShipmentSummaryDto
    {
        $parent = $this->originalShipmentFor($sellerOrderId);

        return $this->createPendingBooking($parent, ShipmentPurpose::Reship, $units, [
            'is_cod' => $codAmountPaise > 0,
            'cod_amount_paise' => $codAmountPaise,
            'declared_value_paise' => $declaredValuePaise,
        ]);
    }

    public function createShipBackBooking(string $sellerOrderId, array $units, int $declaredValuePaise): ShipmentSummaryDto
    {
        return $this->createPendingBooking($this->originalShipmentFor($sellerOrderId), ShipmentPurpose::ShipBack, $units, [
            'is_cod' => false,
            'cod_amount_paise' => 0,
            'declared_value_paise' => $declaredValuePaise,
        ]);
    }

    public function createReturnPickupBooking(string $sellerOrderId, array $units, int $declaredValuePaise, string $returnReason): ShipmentSummaryDto
    {
        $parent = $this->originalShipmentFor($sellerOrderId);

        return $this->createPendingBooking($parent, ShipmentPurpose::CustomerReturn, $units, [
            'is_cod' => false,
            'cod_amount_paise' => 0,
            'declared_value_paise' => $declaredValuePaise,
            'return_reason' => substr($returnReason, 0, 120),
        ]);
    }

    public function bookShipment(string $shipmentId): ShipmentSummaryDto
    {
        // Claim the booking atomically (no lock is held while the courier is called); a second click finds nothing to claim
        $claimed = Shipment::where('id', $shipmentId)
            ->whereIn('booking_status', [Shipment::BOOKING_PENDING, Shipment::BOOKING_FAILED])
            ->update(['booking_status' => Shipment::BOOKING_IN_PROGRESS, 'booking_error' => null]);
        if ($claimed === 0) {
            throw new DomainException('This shipment is not waiting for a courier booking.');
        }

        /** @var Shipment $shipment */
        $shipment = Shipment::with('items')->findOrFail($shipmentId);
        $driver = $this->carrierManager->driver($shipment->provider);

        try {
            $details = $this->shippingDetailsLookup->getShippingDetails($shipment->seller_order_id);
            if (! $details) {
                throw new DomainException("Order details not found for seller order {$shipment->seller_order_id}.");
            }

            $units = $shipment->items->mapWithKeys(fn (ShipmentItem $item) => [$item->order_item_id => $item->qty])->all();
            $bookingDetails = $details->forReship($units, $shipment->cod_amount_paise, (int) $shipment->declared_value_paise);
            $invoice = $this->orderFulfillment->getOrCreateInvoiceNumber($shipment->seller_order_id);
            $requestDto = new CreateShipmentRequestDto(
                sellerOrderId: $shipment->seller_order_id,
                actualWeightGrams: $shipment->actual_weight_grams,
                lengthCm: $shipment->length_cm,
                widthCm: $shipment->width_cm,
                heightCm: $shipment->height_cm,
                preferredDispatchDate: null,
                pickupLocationAlias: 'WH-'.$details->sellerProfileId,
            );

            $response = match (true) {
                $shipment->isReverse() => $driver->createReverseShipment($requestDto, $bookingDetails, $invoice, (string) $shipment->return_reason, $shipment),
                $driver instanceof ShiprocketCarrierDriver => $driver->createShipment($requestDto, $bookingDetails, $invoice, $shipment),
                default => $driver->createShipment($requestDto, $bookingDetails, $invoice),
            };
        } catch (\Throwable $e) {
            $response = new CreateShipmentResponseDto(success: false, awb: null, vendorWbn: null, publicTrackingUrl: null, errorMessage: $e->getMessage());
        }

        if (! $response->success || empty($response->awb)) {
            // Kept for retry: same shipment, same items; nothing is reserved or charged again
            $shipment->update([
                'booking_status' => Shipment::BOOKING_FAILED,
                'booking_error' => substr((string) ($response->errorMessage ?? 'Courier booking failed'), 0, 2000),
            ]);

            return $this->summary($shipment->id);
        }

        $rawMeta = $response->rawResponse ?? [];
        $shipment->update([
            'courier_name' => $rawMeta['courier_name'] ?? $shipment->courier_name,
            'awb' => $response->awb,
            'vendor_wbn' => $response->vendorWbn,
            'provider_order_id' => $shipment->provider_order_id ?? (isset($rawMeta['shiprocket_order_id']) ? (string) $rawMeta['shiprocket_order_id'] : null),
            'provider_shipment_id' => $shipment->provider_shipment_id ?? (isset($rawMeta['shiprocket_shipment_id']) ? (string) $rawMeta['shiprocket_shipment_id'] : null),
            'creation_step' => 'label_ready',
            'booking_status' => Shipment::BOOKING_BOOKED,
            'public_tracking_url' => $response->publicTrackingUrl,
            'estimated_carrier_charges_paise' => $response->estimatedChargesPaise,
            'raw_metadata' => $response->rawResponse,
        ]);

        ShipmentEvent::firstOrCreate(['event_key' => "{$shipment->id}:created"], [
            'shipment_id' => $shipment->id,
            'event_type' => ShipmentEventType::ShipmentCreated,
            'provider' => $driver->name(),
            'status' => ShipmentStatus::Created,
            'provider_status' => 'Created',
            'description' => $shipment->isReverse() ? 'Return pickup booked with carrier' : 'Shipment booked with carrier',
            'occurred_at' => now(),
            'received_at' => now(),
        ]);

        if (! $shipment->isReverse()) {
            try {
                $labelPath = "shipping_labels/{$shipment->id}.pdf";
                Storage::disk('local')->put($labelPath, $driver->downloadLabel((string) $shipment->awb));
                $shipment->update(['label_path' => $labelPath, 'label_generated_at' => now()]);
            } catch (\Throwable $e) {
                Log::warning('Label download deferred', ['shipment_id' => $shipment->id, 'error' => $e->getMessage()]);
            }
        }

        return $this->summary($shipment->id);
    }

    protected function originalShipmentFor(string $sellerOrderId): Shipment
    {
        /** @var Shipment|null $parent */
        $parent = Shipment::where('seller_order_id', $sellerOrderId)
            ->where('direction', ShipmentDirection::Forward->value)
            ->where('purpose', ShipmentPurpose::Original->value)
            ->where('status', '!=', ShipmentStatus::Cancelled->value)
            ->latest()
            ->first();

        if (! $parent) {
            throw new DomainException("Seller order {$sellerOrderId} has no original shipment.");
        }

        return $parent;
    }

    /**
     * Database only (safe inside a transaction): the shipment row and its items, waiting for bookShipment().
     *
     * @param  array<int, int>  $units
     * @param  array<string, mixed>  $attributes
     */
    protected function createPendingBooking(Shipment $parent, ShipmentPurpose $purpose, array $units, array $attributes): ShipmentSummaryDto
    {
        /** @var Shipment $shipment */
        $shipment = Shipment::create($attributes + [
            'seller_order_id' => $parent->seller_order_id,
            'order_id' => $parent->order_id,
            'seller_profile_id' => $parent->seller_profile_id,
            'direction' => $purpose->direction(),
            'purpose' => $purpose,
            'parent_shipment_id' => $parent->id,
            // Same courier and box as the original package
            'provider' => $parent->provider,
            'fulfillment_attempt' => Shipment::where('seller_order_id', $parent->seller_order_id)->count() + 1,
            'creation_step' => 'pending',
            'booking_status' => Shipment::BOOKING_PENDING,
            'status' => ShipmentStatus::Created,
            'actual_weight_grams' => $parent->actual_weight_grams,
            'volumetric_weight_grams' => $parent->volumetric_weight_grams,
            'chargeable_weight_grams' => $parent->chargeable_weight_grams,
            'length_cm' => $parent->length_cm,
            'width_cm' => $parent->width_cm,
            'height_cm' => $parent->height_cm,
        ]);

        foreach ($units as $orderItemId => $count) {
            ShipmentItem::create(['shipment_id' => $shipment->id, 'order_item_id' => (int) $orderItemId, 'qty' => (int) $count]);
        }

        return $this->summary($shipment->id);
    }

    protected function summary(string $shipmentId): ShipmentSummaryDto
    {
        /** @var ShipmentSummaryDto $summary */
        $summary = app(ShipmentLookupInterface::class)->getShipmentById($shipmentId);

        return $summary;
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
                'event_key' => "{$shipment->id}:cancelled",
                'event_type' => ShipmentEventType::ShipmentCancelled,
                'provider' => $shipment->provider,
                'status' => ShipmentStatus::Cancelled,
                'provider_status' => 'Cancelled',
                'description' => $reason ?? 'Shipment cancelled',
                'occurred_at' => now(),
                'received_at' => now(),
            ]);

            // A cancelled return pickup closes the return request; the delivered order is untouched
            if ($shipment->isReverse()) {
                DB::afterCommit(fn () => event(new ShipmentReturnPickupCancelled((string) $shipment->id, (string) $shipment->seller_order_id, $reason)));

                return;
            }

            // A cancelled reship leaves the order as it is; Returns puts the items back in the queue
            if ($shipment->purpose === ShipmentPurpose::Reship) {
                DB::afterCommit(fn () => event(new ShipmentReshipCancelled((string) $shipment->id, (string) $shipment->seller_order_id, $reason)));

                return;
            }

            // Any other secondary shipment (e.g. a ship-back) never reverts the delivered order
            if ($shipment->purpose !== ShipmentPurpose::Original) {
                return;
            }

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
        $eventKey = $this->courierEventKey($shipment, $trackingDto);

        // Idempotency: a courier event already recorded (webhook redelivery, repeated poll) is ignored
        $existing = ShipmentEvent::where('event_key', $eventKey)->first();
        if ($existing) {
            return $existing;
        }

        try {
            return $this->storeCourierEvent($shipment, $trackingDto, $eventKey);
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same courier event won the insert
            return ShipmentEvent::where('event_key', $eventKey)->first();
        }
    }

    /**
     * Build the dedup key from the courier's own data, never from our clock: the courier's
     * event id when it sends one, otherwise a fingerprint of the scan (status, courier
     * timestamp and scan details).
     */
    protected function courierEventKey(Shipment $shipment, TrackingStatusDto $trackingDto): string
    {
        if ($trackingDto->providerEventId !== null && $trackingDto->providerEventId !== '') {
            $eventId = strlen($trackingDto->providerEventId) > 100
                ? sha1($trackingDto->providerEventId)
                : $trackingDto->providerEventId;

            return "{$shipment->id}:{$shipment->provider}:{$eventId}";
        }

        $fingerprint = sha1((string) json_encode([
            $trackingDto->status->value,
            $trackingDto->providerStatus,
            $trackingDto->providerNdrStatus,
            $trackingDto->occurredAt?->format('Y-m-d\TH:i:s.uP'),
            $trackingDto->location,
            $trackingDto->description,
        ]));

        return "{$shipment->id}:{$trackingDto->status->value}:{$fingerprint}";
    }

    protected function storeCourierEvent(Shipment $shipment, TrackingStatusDto $trackingDto, string $eventKey): ShipmentEvent
    {
        return DB::transaction(function () use ($shipment, $trackingDto, $eventKey) {
            /** @var ShipmentEvent $event */
            $event = ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'event_key' => $eventKey,
                'event_type' => ShipmentEventType::CourierUpdate,
                'provider' => $shipment->provider,
                'status' => $trackingDto->status,
                'provider_status' => $trackingDto->providerStatus,
                'provider_ndr_status' => $trackingDto->providerNdrStatus,
                'location' => $trackingDto->location,
                'description' => $trackingDto->description,
                'occurred_at' => $trackingDto->occurredAt ?? now(),
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

            // Return pickups never touch order status; they only report progress of the return
            if ($shipment->isReverse()) {
                $this->applyReverseCourierEvent($shipment, $trackingDto, $updates);

                return $event;
            }

            // Reships and ship-backs carry only some units of an order that already reached the customer;
            // they never change the order status (Returns handles their outcome)
            if ($shipment->purpose !== ShipmentPurpose::Original) {
                $this->applyReshipCourierEvent($shipment, $trackingDto, $updates);

                return $event;
            }

            if ($trackingDto->status === ShipmentStatus::Delivered && ! $shipment->delivered_at) {
                $deliveredAt = $trackingDto->occurredAt ? Carbon::instance($trackingDto->occurredAt) : now();
                $updates['delivered_at'] = $deliveredAt;

                $this->orderFulfillment->markDelivered(
                    sellerOrderId: $shipment->seller_order_id,
                    deliveredAt: $deliveredAt,
                );
            }

            if ($trackingDto->status === ShipmentStatus::DeliveryFailed) {
                $updates['ndr_attempt_count'] = ($shipment->ndr_attempt_count ?? 0) + 1;
                $updates['latest_ndr_reason'] = $trackingDto->description ?? $trackingDto->providerNdrStatus ?? 'Delivery attempt failed';
            }

            if ($trackingDto->status === ShipmentStatus::RtoDelivered) {
                $this->orderFulfillment->markReturnedToOrigin(
                    sellerOrderId: $shipment->seller_order_id,
                    reason: $trackingDto->description ?? 'RTO Delivered to seller',
                );
            }

            if ($trackingDto->status === ShipmentStatus::PartialDelivered) {
                // Keep settlement on hold via Orders contract event
                $this->orderFulfillment->markPartialDelivered($shipment->seller_order_id, $trackingDto->description);
            }

            $shipment->update($updates);

            // Fire domain events after DB transaction commit
            DB::afterCommit(function () use ($shipment, $trackingDto, $updates) {
                match ($trackingDto->status) {
                    ShipmentStatus::DeliveryFailed => event(new ShipmentDeliveryFailed(
                        shipmentId: (string) $shipment->id,
                        sellerOrderId: (string) $shipment->seller_order_id,
                        attemptCount: (int) ($updates['ndr_attempt_count'] ?? 1),
                        reason: $updates['latest_ndr_reason'] ?? null,
                    )),
                    ShipmentStatus::RtoInitiated => event(new ShipmentRtoInitiated(
                        shipmentId: (string) $shipment->id,
                        sellerOrderId: (string) $shipment->seller_order_id,
                        reason: $trackingDto->description,
                    )),
                    ShipmentStatus::RtoDelivered => event(new ShipmentRtoDelivered(
                        shipmentId: (string) $shipment->id,
                        sellerOrderId: (string) $shipment->seller_order_id,
                        reason: $trackingDto->description,
                    )),
                    ShipmentStatus::Lost => event(new ShipmentLost(
                        shipmentId: (string) $shipment->id,
                        sellerOrderId: (string) $shipment->seller_order_id,
                        reason: $trackingDto->description,
                    )),
                    ShipmentStatus::PartialDelivered => event(new ShipmentPartialDelivered(
                        shipmentId: (string) $shipment->id,
                        sellerOrderId: (string) $shipment->seller_order_id,
                        deliveredItemIds: $shipment->partial_delivery_data['delivered_item_ids'] ?? [],
                        undeliveredItemIds: $shipment->partial_delivery_data['undelivered_item_ids'] ?? [],
                    )),
                    default => null,
                };
            });

            return $event;
        });
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    protected function applyReshipCourierEvent(Shipment $shipment, TrackingStatusDto $trackingDto, array $updates): void
    {
        $status = $trackingDto->status;

        if ($status === ShipmentStatus::Delivered && ! $shipment->delivered_at) {
            $updates['delivered_at'] = $trackingDto->occurredAt ? Carbon::instance($trackingDto->occurredAt) : now();
        }

        if ($status === ShipmentStatus::DeliveryFailed) {
            $updates['ndr_attempt_count'] = ($shipment->ndr_attempt_count ?? 0) + 1;
            $updates['latest_ndr_reason'] = $trackingDto->description ?? $trackingDto->providerNdrStatus ?? 'Delivery attempt failed';
        }

        $shipment->update($updates);

        DB::afterCommit(function () use ($shipment, $trackingDto, $updates, $status) {
            $shipmentId = (string) $shipment->id;
            $sellerOrderId = (string) $shipment->seller_order_id;

            $isReship = $shipment->purpose === ShipmentPurpose::Reship;

            match (true) {
                ! $isReship => null,
                $status === ShipmentStatus::Delivered => event(new ShipmentReshipDelivered($shipmentId, $sellerOrderId, Carbon::parse($updates['delivered_at'] ?? $shipment->delivered_at ?? now())->toIso8601String())),
                $status === ShipmentStatus::RtoDelivered => event(new ShipmentReshipReturned($shipmentId, $sellerOrderId, $trackingDto->description)),
                $status === ShipmentStatus::DeliveryFailed => event(new ShipmentDeliveryFailed(
                    shipmentId: $shipmentId,
                    sellerOrderId: $sellerOrderId,
                    attemptCount: (int) $updates['ndr_attempt_count'],
                    reason: $updates['latest_ndr_reason'],
                )),
                // Lost: the admin confirms it through the lost flow, which knows this is a reship
                $status === ShipmentStatus::Lost => event(new ShipmentLost($shipmentId, $sellerOrderId, $trackingDto->description)),
                default => null,
            };
        });
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    protected function applyReverseCourierEvent(Shipment $shipment, TrackingStatusDto $trackingDto, array $updates): void
    {
        $status = $trackingDto->status;

        if ($status === ShipmentStatus::Delivered && ! $shipment->delivered_at) {
            $updates['delivered_at'] = $trackingDto->occurredAt ? Carbon::instance($trackingDto->occurredAt) : now();
        }

        // Pickup attempts reuse the NDR attempt counters (same meaning: a failed courier attempt)
        if ($status === ShipmentStatus::PickupFailed) {
            $updates['ndr_attempt_count'] = ($shipment->ndr_attempt_count ?? 0) + 1;
            $updates['latest_ndr_reason'] = $trackingDto->description ?? $trackingDto->providerNdrStatus ?? 'Pickup attempt failed';
        }

        if ($status === ShipmentStatus::Cancelled && ! $shipment->cancelled_at) {
            $updates['cancelled_at'] = now();
        }

        $shipment->update($updates);

        DB::afterCommit(function () use ($shipment, $trackingDto, $updates, $status) {
            $shipmentId = (string) $shipment->id;
            $sellerOrderId = (string) $shipment->seller_order_id;

            match ($status) {
                ShipmentStatus::Delivered => event(new ShipmentReturnReceived($shipmentId, $sellerOrderId, $trackingDto->description)),
                ShipmentStatus::PickupFailed => event(new ShipmentReturnPickupFailed(
                    shipmentId: $shipmentId,
                    sellerOrderId: $sellerOrderId,
                    attemptCount: (int) $updates['ndr_attempt_count'],
                    reason: $updates['latest_ndr_reason'],
                )),
                ShipmentStatus::Cancelled => event(new ShipmentReturnPickupCancelled($shipmentId, $sellerOrderId, $trackingDto->description)),
                ShipmentStatus::Lost => event(new ShipmentReturnLost($shipmentId, $sellerOrderId, $trackingDto->description)),
                default => null,
            };
        });
    }

    public function actionNdr(string $shipmentId, string $action, array $payload = []): array
    {
        /** @var Shipment $shipment */
        $shipment = Shipment::findOrFail($shipmentId);

        if ($shipment->isReverse() || $shipment->status !== ShipmentStatus::DeliveryFailed) {
            throw new DomainException('NDR actions can only be taken on shipments in Delivery Failed (NDR) status.');
        }

        $normalized = strtolower(trim($action));
        $normalized = match ($normalized) {
            'reattempt', 're-attempt' => 'reattempt',
            'rto', 'return' => 'rto',
            default => throw new DomainException("Unsupported NDR action: {$action}"),
        };

        // Contact/address/date changes only apply to a re-attempt; an RTO carries instructions at most
        $carrierPayload = array_filter([
            'instructions' => trim((string) ($payload['instructions'] ?? '')),
        ]);

        if ($normalized === 'reattempt') {
            $phone = trim((string) ($payload['phone'] ?? ''));
            if ($phone !== '') {
                $phone = $this->normalizeIndianMobile($phone);
            }

            $carrierPayload += array_filter([
                'phone' => $phone,
                'address' => trim((string) ($payload['address'] ?? '')),
                'reattempt_date' => trim((string) ($payload['reattempt_date'] ?? '')),
            ]);
        }

        $driver = $this->carrierManager->driver($shipment->provider);
        $result = $driver->requestNdrAction($shipment->awb ?: $shipment->id, $normalized, $carrierPayload);

        if (! $result['success']) {
            return $result;
        }

        // Updated contact details live on the shipment only; the customer's saved addresses are never touched
        $shipment->update([
            'ndr_action' => $normalized,
            'ndr_actioned_at' => now(),
            'ndr_updated_phone' => $carrierPayload['phone'] ?? $shipment->ndr_updated_phone,
            'ndr_updated_address' => $carrierPayload['address'] ?? $shipment->ndr_updated_address,
        ]);

        // Sync tracking to reflect the updated state immediately
        $this->syncTracking($shipment);

        return $result;
    }

    /**
     * Both carriers accept exactly 10 digits; strip formatting and a +91/0 prefix, reject anything else.
     */
    protected function normalizeIndianMobile(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            throw new DomainException('Updated phone must be a 10-digit mobile number.');
        }

        return $digits;
    }

    public function simulateStatusTransition(
        string $shipmentId,
        ShipmentStatus $status,
        array $extra = []
    ): ShipmentEvent {
        if (! config('shipping.fake_simulation_enabled', false)) {
            throw new DomainException('Fake carrier simulation is disabled.');
        }

        /** @var Shipment $shipment */
        $shipment = Shipment::findOrFail($shipmentId);

        if ($shipment->provider !== 'fake') {
            throw new DomainException('Simulation is only allowed for shipments using the fake carrier.');
        }

        if (! in_array($status, $shipment->nextCourierStatuses(), true)) {
            throw new DomainException("Cannot simulate {$status->labelFor($shipment->direction)} from {$shipment->statusLabel()}.");
        }

        $driver = $this->carrierManager->driver('fake');
        if (! ($driver instanceof FakeCarrierDriver)) {
            throw new DomainException('Fake driver not active.');
        }

        $reason = $extra['reason'] ?? null;
        $location = $extra['location'] ?? null;

        $trackingDto = $driver->simulateStatusChange($shipment->awb ?: $shipment->id, $status, $reason, $location);

        if ($status === ShipmentStatus::PartialDelivered && ! empty($extra['partial_delivery_data'])) {
            $shipment->update(['partial_delivery_data' => $extra['partial_delivery_data']]);
        }

        /** @var ShipmentEvent $event */
        $event = $this->recordShipmentEvent($shipment, $trackingDto);

        return $event;
    }

    public function resolvePartialDelivery(
        string $shipmentId,
        array $deliveredItemIds,
        array $undeliveredItemIds,
        int $adminId,
        ?string $notes = null
    ): ShipmentEvent {
        return DB::transaction(function () use ($shipmentId, $deliveredItemIds, $undeliveredItemIds, $adminId, $notes) {
            /** @var Shipment $shipment */
            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            if ($shipment->status !== ShipmentStatus::PartialDelivered) {
                throw new DomainException('Delivered items can only be confirmed for a shipment the courier marked as Partially Delivered.');
            }

            if (! empty($shipment->partial_delivery_data['resolved_at'])) {
                throw new DomainException('Delivered items for this shipment have already been confirmed.');
            }

            $resolution = [
                'delivered_item_ids' => array_values($deliveredItemIds),
                'undelivered_item_ids' => array_values($undeliveredItemIds),
                'resolved_by_admin_id' => $adminId,
                'resolved_at' => now()->toIso8601String(),
                'notes' => $notes,
            ];

            // Shipment status stays PartialDelivered (the courier's verdict); only the admin's resolution is added
            $shipment->update([
                'partial_delivery_data' => array_merge($shipment->partial_delivery_data ?? [], $resolution),
            ]);

            /** @var ShipmentEvent $event */
            $event = ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'event_key' => "{$shipment->id}:partial_delivery_resolved:".Str::ulid(),
                'event_type' => ShipmentEventType::PartialDeliveryResolved,
                'provider' => $shipment->provider,
                'status' => ShipmentStatus::PartialDelivered,
                'provider_status' => 'Partial Delivery Confirmed',
                'description' => 'Delivered items confirmed: '.count($deliveredItemIds).' delivered, '.count($undeliveredItemIds).' not delivered',
                'occurred_at' => now(),
                'received_at' => now(),
                'raw_payload' => $resolution,
            ]);

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
