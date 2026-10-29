<?php

use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Shipping\Contracts\ShipmentServiceInterface;
use App\Shipping\DTOs\TrackingStatusDto;
use App\Shipping\Enums\ShipmentEventType;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use App\Shipping\Models\ShipmentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $sellerProfile = SellerProfile::factory()->approved()->create(['user_id' => User::factory()->create()->id]);

    $order = Order::create([
        'order_number' => 'ORD-EVTKEY-1',
        'user_id' => User::factory()->create()->id,
        'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Placed,
        'delivery_address' => [
            'name' => 'Buyer',
            'phone' => '9876543210',
            'address_line' => '123 Test St',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
        ],
        'delivery_state' => 'Karnataka',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending,
        'items_mrp_total' => 25000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 25000,
        'placed_at' => now(),
    ]);

    $sellerOrder = SellerOrder::create([
        'order_id' => $order->id,
        'seller_profile_id' => $sellerProfile->id,
        'seller_store_name' => $sellerProfile->store_name,
        'status' => SellerOrderStatus::Shipped,
        'subtotal' => 25000,
        'discount_total' => 0,
        'grand_total' => 25000,
        'placed_at' => now(),
    ]);

    $this->shipment = Shipment::create([
        'order_id' => $order->id,
        'seller_order_id' => $sellerOrder->id,
        'seller_profile_id' => $sellerProfile->id,
        'provider' => 'ekart',
        'status' => ShipmentStatus::InTransit,
        'awb' => 'EK-EVTKEY-1',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    $this->service = app(ShipmentServiceInterface::class);
});

function courierScan(ShipmentStatus $status, array $overrides = []): TrackingStatusDto
{
    return new TrackingStatusDto(...array_merge([
        'awb' => 'EK-EVTKEY-1',
        'status' => $status,
        'providerStatus' => $status->label(),
        'location' => 'Bengaluru Hub',
        'description' => $status->label(),
        'occurredAt' => Carbon::parse('2026-10-28 10:15:30'),
    ], $overrides));
}

test('two different courier events in the same second are both stored', function () {
    $this->freezeTime();

    $this->service->recordShipmentEvent($this->shipment, courierScan(ShipmentStatus::InTransit, [
        'location' => 'Bengaluru Hub',
        'description' => 'Arrived at hub',
    ]));
    $this->service->recordShipmentEvent($this->shipment, courierScan(ShipmentStatus::InTransit, [
        'location' => 'Bengaluru Hub',
        'description' => 'Departed hub',
    ]));
    $this->service->recordShipmentEvent($this->shipment, courierScan(ShipmentStatus::OutForDelivery));

    // Same second, same status, distinct courier event ids
    $this->service->recordShipmentEvent($this->shipment, courierScan(ShipmentStatus::DeliveryFailed, ['providerEventId' => 'EK-SCAN-1']));
    $this->service->recordShipmentEvent($this->shipment, courierScan(ShipmentStatus::DeliveryFailed, ['providerEventId' => 'EK-SCAN-2']));

    expect(ShipmentEvent::where('shipment_id', $this->shipment->id)->count())->toBe(5)
        ->and(ShipmentEvent::where('shipment_id', $this->shipment->id)->pluck('event_key')->unique())->toHaveCount(5);
});

test('a duplicate courier event is stored once', function () {
    $scan = courierScan(ShipmentStatus::InTransit);

    $first = $this->service->recordShipmentEvent($this->shipment, $scan);

    // Redelivered later: our clock moved on, the courier payload did not
    $this->travel(5)->minutes();
    $second = $this->service->recordShipmentEvent($this->shipment, $scan);

    expect($second->id)->toBe($first->id)
        ->and(ShipmentEvent::where('shipment_id', $this->shipment->id)->count())->toBe(1)
        ->and($first->event_type)->toBe(ShipmentEventType::CourierUpdate);
});

test('a duplicate courier event with a courier event id is stored once even if scan details differ', function () {
    $this->service->recordShipmentEvent($this->shipment, courierScan(ShipmentStatus::DeliveryFailed, [
        'providerEventId' => 'EK-SCAN-77',
    ]));
    $this->service->recordShipmentEvent($this->shipment, courierScan(ShipmentStatus::DeliveryFailed, [
        'providerEventId' => 'EK-SCAN-77',
        'description' => 'Customer not available (resent)',
    ]));

    $shipment = $this->shipment->fresh();

    expect(ShipmentEvent::where('shipment_id', $shipment->id)->count())->toBe(1)
        ->and($shipment->ndr_attempt_count)->toBe(1);
});

test('a courier event without a courier timestamp is deduplicated across polls', function () {
    $scan = courierScan(ShipmentStatus::InTransit, ['occurredAt' => null]);

    $this->service->recordShipmentEvent($this->shipment, $scan);
    $this->travel(10)->minutes();
    $this->service->recordShipmentEvent($this->shipment, $scan);

    $events = ShipmentEvent::where('shipment_id', $this->shipment->id)->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->occurred_at)->not->toBeNull();
});
