<?php

use App\Models\Admin;
use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('permissions:sync');

    $this->admin = Admin::factory()->create();
    $role = Role::findOrCreate('Super Admin', 'admin');
    $this->admin->assignRole($role);

    $this->sellerUser = User::factory()->create();
    $this->sellerProfile = SellerProfile::factory()->approved()->create(['user_id' => $this->sellerUser->id]);

    $this->customer = User::factory()->create();
    $this->order = Order::create([
        'order_number' => 'SIM-ORD-'.strtoupper(Str::random(6)),
        'user_id' => $this->customer->id,
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

    $this->sellerOrder = SellerOrder::create([
        'order_id' => $this->order->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'seller_store_name' => $this->sellerProfile->store_name,
        'status' => SellerOrderStatus::Packed,
        'subtotal' => 25000,
        'discount_total' => 0,
        'grand_total' => 25000,
        'placed_at' => now(),
    ]);
});

test('fake simulation toolbar is hidden when simulation is disabled in config', function () {
    config(['shipping.fake_simulation_enabled' => false]);

    $shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::Created,
        'awb' => 'SIM-123456',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.shipments.show', ['shipment' => $shipment->id]))
        ->assertOk()
        ->assertDontSee('Carrier Simulation Toolbar (Fake Driver)')
        ->assertDontSee('data-test="fake-simulation-toolbar"', false);
});

test('fake simulation toolbar is hidden for real carriers even if simulation is enabled', function () {
    config(['shipping.fake_simulation_enabled' => true]);

    $shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'ekart',
        'status' => ShipmentStatus::Created,
        'awb' => 'EKART-999999',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.shipments.show', ['shipment' => $shipment->id]))
        ->assertOk()
        ->assertDontSee('Carrier Simulation Toolbar (Fake Driver)');
});

test('admin can simulate every fake milestone and events are recorded identically to webhooks', function () {
    config(['shipping.fake_simulation_enabled' => true]);

    $shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::Created,
        'awb' => 'SIM-FLOW-123',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    $transitions = [
        'picked_up' => ShipmentStatus::PickedUp,
        'in_transit' => ShipmentStatus::InTransit,
        'out_for_delivery' => ShipmentStatus::OutForDelivery,
        'delivered' => ShipmentStatus::Delivered,
    ];

    foreach ($transitions as $statusKey => $expectedStatus) {
        Livewire::actingAs($this->admin, 'admin')
            ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
            ->call('simulateTransition', $statusKey)
            ->assertHasNoErrors();

        expect($shipment->fresh()->status)->toBe($expectedStatus);
    }

    expect($shipment->events()->count())->toBe(4);
});

test('fake simulation is disabled unless SHIPPING_FAKE_SIMULATION_ENABLED is set', function () {
    // Check the shipped default itself, independent of a developer's local .env
    expect(file_get_contents(config_path('shipping.php')))
        ->toContain("'fake_simulation_enabled' => (bool) env('SHIPPING_FAKE_SIMULATION_ENABLED', false)");
});

test('the toolbar offers only valid next statuses for the current status', function () {
    config(['shipping.fake_simulation_enabled' => true]);

    $shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::Created,
        'awb' => 'SIM-NEXT-1',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    $page = fn () => $this->actingAs($this->admin, 'admin')->get(route('admin.shipments.show', ['shipment' => $shipment->id]));

    $page()->assertSee('data-test="simulate-picked_up"', false)
        ->assertSee('data-test="simulate-lost"', false)
        ->assertDontSee('data-test="simulate-delivered"', false)
        ->assertDontSee('data-test="simulate-rto_delivered"', false)
        ->assertDontSee('data-test="simulate-partial_delivered"', false);

    $shipment->update(['status' => ShipmentStatus::OutForDelivery]);
    $page()->assertSee('data-test="simulate-delivered"', false)
        ->assertSee('data-test="simulate-delivery_failed"', false)
        ->assertSee('data-test="simulate-partial_delivered"', false)
        ->assertDontSee('data-test="simulate-picked_up"', false);

    $shipment->update(['status' => ShipmentStatus::Delivered]);
    $page()->assertSee('data-test="simulate-none"', false)
        ->assertDontSee('data-test="simulate-lost"', false);
});

test('an invalid simulated transition is rejected on the server', function () {
    config(['shipping.fake_simulation_enabled' => true]);

    $shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::Created,
        'awb' => 'SIM-INVALID-1',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call('simulateTransition', 'delivered')
        ->assertSee('Cannot simulate Delivered from Created.');

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Created)
        ->and($shipment->events()->count())->toBe(0);
});

test('simulation is rejected on the server when the flag is off, even for fake shipments', function () {
    config(['shipping.fake_simulation_enabled' => false]);

    $shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::Created,
        'awb' => 'SIM-OFF-1',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call('simulateTransition', 'picked_up')
        ->assertSee('Fake carrier simulation is disabled.');

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Created);
});
