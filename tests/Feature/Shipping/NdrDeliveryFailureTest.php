<?php

use App\Models\Admin;
use App\Models\CustomerAddress;
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
        'order_number' => 'ORD-NDR-TEST-1',
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
        'status' => SellerOrderStatus::Shipped,
        'subtotal' => 25000,
        'discount_total' => 0,
        'grand_total' => 25000,
        'placed_at' => now(),
    ]);
});

test('delivery failure updates attempt count and customer sees delivery failed notice', function () {
    config(['shipping.fake_simulation_enabled' => true]);

    $shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::OutForDelivery,
        'awb' => 'SIM-NDR-999',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    // Simulate delivery failure (NDR)
    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->set('simulationReason', 'Customer door locked / unreachable')
        ->call('simulateTransition', 'delivery_failed')
        ->assertHasNoErrors();

    $fresh = $shipment->fresh();
    expect($fresh->status)->toBe(ShipmentStatus::DeliveryFailed)
        ->and($fresh->ndr_attempt_count)->toBe(1)
        ->and($fresh->latest_ndr_reason)->toBe('Customer door locked / unreachable');

    // Customer visits order details page
    $this->actingAs($this->customer)
        ->get(route('orders.show', ['order' => $this->order->order_number]))
        ->assertOk()
        ->assertSee('Delivery attempt failed')
        ->assertSee('(attempt 1)')
        ->assertSee('Customer door locked / unreachable');
});

test('admin can action NDR with re-attempt or RTO', function () {
    $shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::DeliveryFailed,
        'awb' => 'SIM-NDR-ACT',
        'ndr_attempt_count' => 1,
        'latest_ndr_reason' => 'Customer unavailable',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    // Action Re-attempt with updated phone & instructions
    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->set('ndrAction', 'reattempt')
        ->set('ndrPhone', '9876543210')
        ->set('ndrAddress', 'Call before delivery')
        ->call('submitNdr')
        ->assertHasNoErrors()
        ->assertSee('Simulated re-attempt scheduled successfully');

    $fresh = $shipment->fresh();
    expect($fresh->ndr_action)->toBe('reattempt')
        ->and($fresh->ndr_updated_phone)->toBe('9876543210')
        ->and($fresh->ndr_updated_address)->toBe('Call before delivery')
        ->and($fresh->ndr_actioned_at)->not->toBeNull();
});

test('admin re-attempt changes phone and address on the shipment only, never the customer\'s saved address', function () {
    $savedAddress = CustomerAddress::create([
        'user_id' => $this->customer->id,
        'name' => 'Buyer',
        'phone' => '9876543210',
        'pincode' => '560001',
        'locality' => 'MG Road',
        'address_line' => '123 Test St',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'is_default' => true,
    ]);
    $orderAddressBefore = $this->order->fresh()->delivery_address;

    $shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::DeliveryFailed,
        'awb' => 'SIM-NDR-ADDR',
        'ndr_attempt_count' => 1,
        'latest_ndr_reason' => 'Address incomplete',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->set('ndrAction', 'reattempt')
        ->set('ndrPhone', '+91 91234 56789')
        ->set('ndrAddress', 'Flat 12B, Tower 3, Prestige Park, Bengaluru 560001')
        ->set('ndrInstructions', 'Call before delivery')
        ->call('submitNdr')
        ->assertSee('Simulated re-attempt scheduled successfully');

    $fresh = $shipment->fresh();
    expect($fresh->ndr_updated_phone)->toBe('9123456789')
        ->and($fresh->ndr_updated_address)->toBe('Flat 12B, Tower 3, Prestige Park, Bengaluru 560001')
        ->and($savedAddress->fresh()->only(['phone', 'address_line', 'locality', 'pincode']))->toBe([
            'phone' => '9876543210',
            'address_line' => '123 Test St',
            'locality' => 'MG Road',
            'pincode' => '560001',
        ])
        ->and($this->order->fresh()->delivery_address)->toBe($orderAddressBefore);
});

test('admin re-attempt with an invalid phone is rejected and nothing is changed', function () {
    $shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::DeliveryFailed,
        'awb' => 'SIM-NDR-BADPHONE',
        'ndr_attempt_count' => 1,
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->set('ndrAction', 'reattempt')
        ->set('ndrPhone', '12345')
        ->call('submitNdr')
        ->assertSee('Updated phone must be a 10-digit mobile number.');

    $fresh = $shipment->fresh();
    expect($fresh->ndr_action)->toBeNull()
        ->and($fresh->ndr_updated_phone)->toBeNull();
});

test('customer order page shows the failure reason and the latest attempt count', function () {
    Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::DeliveryFailed,
        'awb' => 'SIM-NDR-SECOND',
        'ndr_attempt_count' => 2,
        'latest_ndr_reason' => 'Premises closed',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    $this->actingAs($this->customer)
        ->get(route('orders.show', ['order' => $this->order->order_number]))
        ->assertOk()
        ->assertSee('Delivery attempt failed')
        ->assertSee('(attempt 2)')
        ->assertSee('Reason: Premises closed');
});
