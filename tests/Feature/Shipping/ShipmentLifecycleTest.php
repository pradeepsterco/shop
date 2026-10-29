<?php

use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerInvoiceSequence;
use App\Orders\Models\SellerOrder;
use App\Shipping\Contracts\CarrierManagerInterface;
use App\Shipping\Contracts\ShipmentServiceInterface;
use App\Shipping\Drivers\FakeCarrierDriver;
use App\Shipping\Enums\ShipmentDirection;
use App\Shipping\Enums\ShipmentPurpose;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['shipping.default' => 'fake']);

    $this->carrierManager = app(CarrierManagerInterface::class);
    $this->fakeDriver = new FakeCarrierDriver;
    $this->carrierManager->setDriverInstance('fake', $this->fakeDriver);
    $this->carrierManager->setActiveDriver('fake');

    $this->sellerUser = User::factory()->create();
    $this->seller = SellerProfile::factory()->create([
        'user_id' => $this->sellerUser->id,
        'store_name' => 'FunWorld Toys',
        'pickup_address_line1' => 'Plot 45, Sector 5',
        'pickup_city' => 'Bengaluru',
        'pickup_state' => 'Karnataka',
        'pickup_postal_code' => '560001',
    ]);

    $this->customer = User::factory()->create();

    $this->order = Order::create([
        'order_number' => 'KH-'.date('Ymd').'-'.strtoupper(Str::random(6)),
        'user_id' => $this->customer->id,
        'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Placed,
        'delivery_address' => [
            'name' => 'Aarav Buyer',
            'phone' => '9876543210',
            'address_line' => 'Flat 202',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560038',
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

    $this->sellerOrder = SellerOrder::create([
        'order_id' => $this->order->id,
        'seller_profile_id' => $this->seller->id,
        'seller_store_name' => $this->seller->store_name,
        'status' => SellerOrderStatus::Packed,
        'subtotal' => 25000,
        'discount_total' => 0,
        'grand_total' => 25000,
        'placed_at' => now(),
    ]);
});

test('ready to ship creates shipment with correct volumetric & chargeable weight and marks order shipped', function () {
    $service = app(ShipmentServiceInterface::class);

    // Box: 600g actual, 20x15x10 cm -> Volumetric: (20*15*10)/5000 * 1000 = 600g
    // Let's use 20x20x20 cm -> Volumetric: 8000/5000 * 1000 = 1600g -> Chargeable = 1600g
    $shipment = $service->createShipmentForSellerOrder(
        sellerOrderId: $this->sellerOrder->id,
        actualWeightGrams: 500,
        lengthCm: 20,
        widthCm: 20,
        heightCm: 20,
        pickupDate: '2026-10-28',
    );

    expect($shipment->actual_weight_grams)->toBe(500)
        ->and($shipment->volumetric_weight_grams)->toBe(1600)
        ->and($shipment->chargeable_weight_grams)->toBe(1600)
        ->and($shipment->status)->toBe(ShipmentStatus::Created)
        ->and($shipment->awb)->not->toBeEmpty();

    $this->sellerOrder->refresh();
    expect($this->sellerOrder->status)->toBe(SellerOrderStatus::Shipped)
        ->and($this->sellerOrder->invoice_number)->not->toBeNull()
        ->and($this->sellerOrder->invoice_date)->not->toBeNull();
});

test('cancelling shipment cancels only the shipment and reverts order to Packed', function () {
    $service = app(ShipmentServiceInterface::class);

    $shipment = $service->createShipmentForSellerOrder(
        sellerOrderId: $this->sellerOrder->id,
        actualWeightGrams: 500,
        lengthCm: 15,
        widthCm: 10,
        heightCm: 5,
    );

    $this->sellerOrder->refresh();
    expect($this->sellerOrder->status)->toBe(SellerOrderStatus::Shipped);

    // Cancel shipment
    $service->cancelShipment(
        shipmentId: $shipment->id,
        actorType: 'seller',
        actorId: $this->sellerUser->id,
        reason: 'Customer requested rescheduling',
    );

    $shipment->refresh();
    expect($shipment->status)->toBe(ShipmentStatus::Cancelled)
        ->and($shipment->cancelled_at)->not->toBeNull();

    // User requirement 2: "Shipment cancellation cancels only the shipment; order returns to Packed."
    $this->sellerOrder->refresh();
    expect($this->sellerOrder->status)->toBe(SellerOrderStatus::Packed);
});

test('re-uses the same invoice number when shipment is cancelled and re-created without consuming a new number', function () {
    $service = app(ShipmentServiceInterface::class);

    // 1. Initial shipment creation generates the invoice number
    $shipment1 = $service->createShipmentForSellerOrder(
        sellerOrderId: $this->sellerOrder->id,
        actualWeightGrams: 500,
        lengthCm: 15,
        widthCm: 10,
        heightCm: 5,
    );

    $this->sellerOrder->refresh();
    $firstInvoiceNumber = $this->sellerOrder->invoice_number;
    expect($firstInvoiceNumber)->not->toBeNull();

    $seq = SellerInvoiceSequence::where('seller_profile_id', $this->seller->id)->first();
    expect($seq)->not->toBeNull()
        ->and($seq->last_sequence)->toBe(1);

    // 2. Shipment is cancelled -> order reverts to Packed
    $service->cancelShipment(
        shipmentId: $shipment1->id,
        actorType: 'seller',
        actorId: $this->sellerUser->id,
        reason: 'Customer requested change of address before dispatch',
    );

    $this->sellerOrder->refresh();
    expect($this->sellerOrder->status)->toBe(SellerOrderStatus::Packed)
        ->and($this->sellerOrder->invoice_number)->toBe($firstInvoiceNumber);

    // 3. Re-create shipment for the same seller order
    $shipment2 = $service->createShipmentForSellerOrder(
        sellerOrderId: $this->sellerOrder->id,
        actualWeightGrams: 600,
        lengthCm: 15,
        widthCm: 10,
        heightCm: 5,
    );

    $this->sellerOrder->refresh();
    // Re-uses exact same invoice number
    expect($this->sellerOrder->invoice_number)->toBe($firstInvoiceNumber);

    // Sequence must still be 1 (no new number consumed)
    $seq->refresh();
    expect($seq->last_sequence)->toBe(1);
});

test('poll command skips shipments in terminal statuses (delivered, rto_delivered, cancelled, lost) and processes in limited batches', function () {
    $terminalStatuses = [
        ShipmentStatus::Delivered,
        ShipmentStatus::RtoDelivered,
        ShipmentStatus::Cancelled,
        ShipmentStatus::Lost,
    ];

    foreach ($terminalStatuses as $index => $status) {
        Shipment::create([
            'seller_order_id' => 'so-term-'.$index,
            'order_id' => 'ord-term-'.$index,
            'seller_profile_id' => $this->seller->id,
            'provider' => 'fake',
            'awb' => 'AWB-TERM-'.$index,
            'status' => $status,
            'actual_weight_grams' => 500,
            'volumetric_weight_grams' => 500,
            'chargeable_weight_grams' => 500,
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 10,
            'last_polled_at' => now()->subHours(2),
        ]);
    }

    // Create 3 active shipments
    for ($i = 1; $i <= 3; $i++) {
        Shipment::create([
            'seller_order_id' => 'so-act-'.$i,
            'order_id' => 'ord-act-'.$i,
            'seller_profile_id' => $this->seller->id,
            'provider' => 'fake',
            'awb' => 'AWB-ACT-'.$i,
            'status' => ShipmentStatus::InTransit,
            'actual_weight_grams' => 500,
            'volumetric_weight_grams' => 500,
            'chargeable_weight_grams' => 500,
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 10,
            'last_polled_at' => now()->subHours(2),
        ]);
    }

    // Run poll command with limit=2
    Artisan::call('shipping:poll-tracking', ['--limit' => 2]);

    // Check that terminal shipments were never polled
    foreach ($terminalStatuses as $index => $status) {
        $term = Shipment::where('awb', 'AWB-TERM-'.$index)->first();
        expect($term->last_polled_at->lt(now()->subHour()))->toBeTrue();
    }

    // Exactly 2 active shipments should have been polled because limit=2
    $polledCount = Shipment::whereIn('awb', ['AWB-ACT-1', 'AWB-ACT-2', 'AWB-ACT-3'])
        ->where('last_polled_at', '>=', now()->subMinutes(1))
        ->count();

    expect($polledCount)->toBe(2);
});

test('a return pickup on the seller order does not block or count as a forward shipment attempt', function () {
    $reverse = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->seller->id,
        'direction' => ShipmentDirection::Reverse,
        'purpose' => ShipmentPurpose::CustomerReturn,
        'provider' => 'fake',
        'status' => ShipmentStatus::Created,
        'creation_step' => 'label_ready',
        'awb' => 'SIM-REV-LIFECYCLE',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    $forward = app(ShipmentServiceInterface::class)->createShipmentForSellerOrder(
        sellerOrderId: $this->sellerOrder->id,
        actualWeightGrams: 500,
        lengthCm: 10,
        widthCm: 10,
        heightCm: 10,
    );

    expect($forward->id)->not->toBe($reverse->id)
        ->and($forward->direction)->toBe(ShipmentDirection::Forward)
        ->and($forward->purpose)->toBe(ShipmentPurpose::Original)
        ->and($forward->fulfillment_attempt)->toBe(1);
});
