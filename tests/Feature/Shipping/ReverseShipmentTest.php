<?php

use App\Models\Admin;
use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;
use App\Shipping\Contracts\ShipmentLookupInterface;
use App\Shipping\Contracts\ShipmentServiceInterface;
use App\Shipping\DTOs\TrackingStatusDto;
use App\Shipping\Enums\ShipmentDirection;
use App\Shipping\Enums\ShipmentPurpose;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Events\ShipmentLost;
use App\Shipping\Events\ShipmentReturnLost;
use App\Shipping\Events\ShipmentReturnPickupCancelled;
use App\Shipping\Events\ShipmentReturnPickupFailed;
use App\Shipping\Events\ShipmentReturnReceived;
use App\Shipping\Models\Shipment;
use App\Shipping\Models\ShipmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('permissions:sync');
    config(['shipping.fake_simulation_enabled' => true]);

    $this->admin = Admin::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('Super Admin', 'admin'));

    $sellerProfile = SellerProfile::factory()->approved()->create(['user_id' => User::factory()->create()->id]);
    $order = Order::create([
        'order_number' => 'ORD-REVERSE-1',
        'user_id' => User::factory()->create()->id,
        'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Completed,
        'delivery_address' => ['name' => 'Buyer', 'phone' => '9876543210', 'address_line' => '1 St', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'],
        'delivery_state' => 'Maharashtra',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Completed,
        'items_mrp_total' => 30000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 30000,
        'placed_at' => now(),
    ]);
    $this->sellerOrder = SellerOrder::create([
        'order_id' => $order->id,
        'seller_profile_id' => $sellerProfile->id,
        'seller_store_name' => $sellerProfile->store_name,
        'status' => SellerOrderStatus::Delivered,
        'subtotal' => 30000,
        'discount_total' => 0,
        'grand_total' => 30000,
        'placed_at' => now(),
        'delivered_at' => now()->subDay(),
    ]);
    $this->item = OrderItem::create([
        'seller_order_id' => $this->sellerOrder->id,
        'listing_id' => null,
        'product_variant_id' => null,
        'sku' => 'SKU-REV-1',
        'upid' => 'UPID-REV-1',
        'seller_name' => $sellerProfile->store_name,
        'title' => 'Returned Toy',
        'mrp' => 10000,
        'qty' => 3,
        'unit_price' => 10000,
        'line_total' => 30000,
    ]);

    $base = [
        'order_id' => $order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $sellerProfile->id,
        'provider' => 'fake',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ];

    $this->original = Shipment::create($base + ['status' => ShipmentStatus::Delivered, 'awb' => 'SIM-FWD-1', 'delivered_at' => now()->subDay()]);

    $this->travel(1)->minutes();
    $this->reverse = Shipment::create($base + [
        'direction' => ShipmentDirection::Reverse,
        'purpose' => ShipmentPurpose::CustomerReturn,
        'parent_shipment_id' => $this->original->id,
        'status' => ShipmentStatus::Created,
        'awb' => 'SIM-REV-1',
    ]);
    ShipmentItem::create(['shipment_id' => $this->reverse->id, 'order_item_id' => $this->item->id, 'qty' => 2]);

    $this->scan = fn (ShipmentStatus $status, string $id) => app(ShipmentServiceInterface::class)->recordShipmentEvent(
        $this->reverse->fresh(),
        new TrackingStatusDto(awb: 'SIM-REV-1', status: $status, providerStatus: $status->label(), description: "{$status->label()} scan", occurredAt: now(), providerEventId: $id),
    );
});

test('the package lookup returns the original forward shipment even when a newer return pickup exists', function () {
    $lookup = app(ShipmentLookupInterface::class);

    $package = $lookup->getShipmentForSellerOrder($this->sellerOrder->id);
    expect($package->id)->toBe($this->original->id)
        ->and($package->direction)->toBe(ShipmentDirection::Forward);

    $all = $lookup->getShipmentsForSellerOrder($this->sellerOrder->id);
    $reverseOnly = $lookup->getShipmentsForSellerOrder($this->sellerOrder->id, ShipmentDirection::Reverse);

    expect(array_map(fn ($s) => $s->id, $all))->toBe([$this->original->id, $this->reverse->id])
        ->and($reverseOnly)->toHaveCount(1)
        ->and($reverseOnly[0]->purpose)->toBe(ShipmentPurpose::CustomerReturn)
        ->and($reverseOnly[0]->parentShipmentId)->toBe($this->original->id)
        ->and($reverseOnly[0]->items)->toBe([(int) $this->item->id => 2])
        ->and($reverseOnly[0]->statusLabel())->toBe('Created');
});

test('return pickup courier events report the return and never change the order status', function () {
    Event::fake([ShipmentReturnReceived::class, ShipmentReturnPickupFailed::class, ShipmentReturnPickupCancelled::class, ShipmentReturnLost::class, ShipmentLost::class]);

    ($this->scan)(ShipmentStatus::PickupFailed, 'EV-1');
    ($this->scan)(ShipmentStatus::PickupFailed, 'EV-2');
    ($this->scan)(ShipmentStatus::PickedUp, 'EV-3');
    ($this->scan)(ShipmentStatus::InTransit, 'EV-4');
    ($this->scan)(ShipmentStatus::Delivered, 'EV-5');

    $reverse = $this->reverse->fresh();
    expect($reverse->status)->toBe(ShipmentStatus::Delivered)
        ->and($reverse->statusLabel())->toBe('Returned to Seller')
        ->and($reverse->delivered_at)->not->toBeNull()
        ->and($reverse->ndr_attempt_count)->toBe(2)
        ->and($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Delivered)
        ->and($this->original->fresh()->status)->toBe(ShipmentStatus::Delivered);

    Event::assertDispatchedTimes(ShipmentReturnPickupFailed::class, 2);
    Event::assertDispatched(ShipmentReturnPickupFailed::class, fn ($e) => $e->attemptCount === 2 && $e->shipmentId === $this->reverse->id);
    Event::assertDispatched(ShipmentReturnReceived::class, fn ($e) => $e->shipmentId === $this->reverse->id);
});

test('a lost or cancelled return pickup fires return events, not the forward lost event', function () {
    Event::fake([ShipmentReturnLost::class, ShipmentReturnPickupCancelled::class, ShipmentLost::class]);

    ($this->scan)(ShipmentStatus::PickedUp, 'EV-L1');
    ($this->scan)(ShipmentStatus::Lost, 'EV-L2');

    Event::assertDispatched(ShipmentReturnLost::class);
    Event::assertNotDispatched(ShipmentLost::class);

    $this->reverse->update(['status' => ShipmentStatus::PickupFailed]);
    ($this->scan)(ShipmentStatus::Cancelled, 'EV-C1');

    Event::assertDispatched(ShipmentReturnPickupCancelled::class);
    expect($this->reverse->fresh()->cancelled_at)->not->toBeNull()
        ->and($this->reverse->fresh()->statusLabel())->toBe('Return Pickup Cancelled');
});

test('the fake toolbar offers return pickup steps and simulates the whole reverse lifecycle', function () {
    $page = fn () => $this->actingAs($this->admin, 'admin')->get(route('admin.shipments.show', ['shipment' => $this->reverse->id]));

    $page()->assertOk()
        ->assertSee('Return Pickup (Customer Return)')
        ->assertSee('data-test="simulate-pickup_scheduled"', false)
        ->assertSee('data-test="simulate-pickup_failed"', false)
        ->assertSee('Picked Up from Customer')
        ->assertDontSee('data-test="simulate-out_for_delivery"', false)
        ->assertDontSee('data-test="simulate-delivered"', false)
        ->assertSee('data-test="shipment-items-card"', false);

    foreach (['pickup_failed', 'pickup_scheduled', 'picked_up', 'in_transit', 'delivered'] as $step) {
        Livewire::actingAs($this->admin, 'admin')
            ->test('pages::admin.shipments-show', ['shipment' => $this->reverse->id])
            ->call('simulateTransition', $step)
            ->assertSee('successfully');
    }

    expect($this->reverse->fresh()->status)->toBe(ShipmentStatus::Delivered)
        ->and($this->reverse->events()->count())->toBe(5)
        ->and($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Delivered);

    $page()->assertSee('Status: Returned to Seller')->assertSee('data-test="simulate-none"', false);
});

test('invalid reverse steps and forward-only actions are rejected for a return pickup', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $this->reverse->id])
        ->call('simulateTransition', 'out_for_delivery')
        ->assertSee('Cannot simulate Out for Delivery from Created.');

    $this->reverse->update(['status' => ShipmentStatus::Lost, 'declared_value_paise' => 20000]);

    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.shipments.show', ['shipment' => $this->reverse->id]))
        ->assertDontSee('data-test="lost-shipment-card"', false);

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $this->reverse->id])
        ->call('confirmLostShipment')
        ->assertSee('Only a shipment the courier reported as Lost can be confirmed lost.');

    expect(fn () => app(ShipmentServiceInterface::class)->actionNdr($this->reverse->id, 'reattempt'))
        ->toThrow(DomainException::class);
});
