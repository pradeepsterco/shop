<?php

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;
use App\Returns\Enums\CourierClaimStatus;
use App\Returns\Enums\RtoAdminDecision;
use App\Returns\Models\CourierClaim;
use App\Returns\Models\RtoIntakeReport;
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
    config(['shipping.fake_simulation_enabled' => true]);

    $this->admin = Admin::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('Super Admin', 'admin'));
    $this->sellerProfile = SellerProfile::factory()->approved()->create(['user_id' => User::factory()->create()->id]);

    $this->makeShipment = function (ShipmentStatus $status, SellerOrderStatus $orderStatus = SellerOrderStatus::Shipped): Shipment {
        $order = Order::create([
            'order_number' => 'ORD-ACT-'.Str::upper(Str::random(6)),
            'user_id' => User::factory()->create()->id,
            'checkout_intent_id' => (string) Str::ulid(),
            'status' => OrderStatus::Placed,
            'delivery_address' => ['name' => 'Buyer', 'phone' => '9876543210', 'address_line' => '1 St', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'],
            'delivery_state' => 'Maharashtra',
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Pending,
            'items_mrp_total' => 20000,
            'discount_total' => 0,
            'delivery_fee' => 0,
            'grand_total' => 20000,
            'placed_at' => now(),
        ]);
        $sellerOrder = SellerOrder::create([
            'order_id' => $order->id,
            'seller_profile_id' => $this->sellerProfile->id,
            'seller_store_name' => $this->sellerProfile->store_name,
            'status' => $orderStatus,
            'subtotal' => 20000,
            'discount_total' => 0,
            'grand_total' => 20000,
            'placed_at' => now(),
            'rto_delivered_at' => $orderStatus === SellerOrderStatus::ReturnedToOrigin ? now() : null,
        ]);
        foreach ([1, 2] as $n) {
            OrderItem::create([
                'seller_order_id' => $sellerOrder->id,
                'listing_id' => null,
                'product_variant_id' => null,
                'sku' => "SKU-ACT-{$n}",
                'upid' => "UPID-ACT-{$n}",
                'seller_name' => $this->sellerProfile->store_name,
                'title' => "Item {$n}",
                'mrp' => 10000,
                'qty' => 1,
                'unit_price' => 10000,
                'line_total' => 10000,
            ]);
        }

        return Shipment::create([
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'seller_profile_id' => $this->sellerProfile->id,
            'provider' => 'fake',
            'status' => $status,
            'awb' => 'SIM-ACT-'.Str::upper(Str::random(6)),
            'declared_value_paise' => 20000,
            'actual_weight_grams' => 500,
            'volumetric_weight_grams' => 500,
            'chargeable_weight_grams' => 500,
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 10,
        ]);
    };

    $this->page = fn (Shipment $shipment) => Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id]);

    $this->logged = fn (string $action, Shipment $shipment) => AdminActivityLog::where('action', $action)
        ->where('admin_id', $this->admin->id)
        ->where('subject_id', $shipment->id)
        ->exists();
});

test('every admin shipment and returns action writes an activity log entry', function () {
    // NDR + simulation
    $ndr = ($this->makeShipment)(ShipmentStatus::OutForDelivery);
    ($this->page)($ndr)->call('simulateTransition', 'delivery_failed');
    ($this->page)($ndr)->set('ndrAction', 'reattempt')->call('submitNdr');
    ($this->page)($ndr)->call('pollTracking');

    // Lost + claim
    $lost = ($this->makeShipment)(ShipmentStatus::Lost);
    ($this->page)($lost)->call('confirmLostShipment')->assertSee('Shipment confirmed lost');
    $claim = CourierClaim::where('shipment_id', $lost->id)->sole();
    ($this->page)($lost)->call('updateClaimStatus', $claim->id, 'approved')->assertSee('marked approved');

    // Partial delivery
    $partial = ($this->makeShipment)(ShipmentStatus::OutForDelivery);
    ($this->page)($partial)->call('simulateTransition', 'partial_delivered');
    $firstItemId = (int) SellerOrder::with('items')->find($partial->seller_order_id)->items->first()->id;
    ($this->page)($partial)->set('selectedDeliveredItems', [$firstItemId])->call('submitPartialDelivery')
        ->assertSee('Partial delivery marked');

    // RTO decision
    $rto = ($this->makeShipment)(ShipmentStatus::RtoDelivered, SellerOrderStatus::ReturnedToOrigin);
    ($this->page)($rto)->call('submitRtoDecision', 'written_off')->assertSee('RTO decision recorded');

    expect(($this->logged)('shipping.simulated_transition', $ndr))->toBeTrue()
        ->and(($this->logged)('shipping.ndr_actioned', $ndr))->toBeTrue()
        ->and(($this->logged)('shipping.tracking_synced', $ndr))->toBeTrue()
        ->and(($this->logged)('returns.lost_confirmed', $lost))->toBeTrue()
        ->and(($this->logged)('returns.claim_status_updated', $lost))->toBeTrue()
        ->and(($this->logged)('returns.partial_delivery_confirmed', $partial))->toBeTrue()
        ->and(($this->logged)('returns.rto_decided', $rto))->toBeTrue();
});

test('courier claims only move to allowed next statuses', function () {
    $lost = ($this->makeShipment)(ShipmentStatus::Lost);
    ($this->page)($lost)->call('confirmLostShipment');
    $claim = CourierClaim::where('shipment_id', $lost->id)->sole();

    ($this->page)($lost)->call('updateClaimStatus', $claim->id, 'settled')
        ->assertSee('A submitted claim cannot be marked settled.');
    expect($claim->fresh()->status)->toBe(CourierClaimStatus::Submitted);

    ($this->page)($lost)->call('updateClaimStatus', $claim->id, 'approved');
    ($this->page)($lost)->call('updateClaimStatus', $claim->id, 'settled');
    expect($claim->fresh()->status)->toBe(CourierClaimStatus::Settled);

    // A claim can only be changed from its own shipment page
    $other = ($this->makeShipment)(ShipmentStatus::Lost);
    ($this->page)($other)->call('updateClaimStatus', $claim->id, 'rejected')
        ->assertSee('This claim does not belong to the shipment.');
});

test('an rto decision can be made only once', function () {
    $rto = ($this->makeShipment)(ShipmentStatus::RtoDelivered, SellerOrderStatus::ReturnedToOrigin);

    ($this->page)($rto)->call('submitRtoDecision', 'written_off')->assertSee('RTO decision recorded');
    ($this->page)($rto)->call('submitRtoDecision', 'restocked')->assertSee('RTO decision already recorded (written_off).');

    expect(RtoIntakeReport::where('seller_order_id', $rto->seller_order_id)->sole()->admin_decision)->toBe(RtoAdminDecision::WrittenOff);
});

test('irreversible admin actions ask for confirmation', function () {
    $html = fn (Shipment $shipment) => $this->actingAs($this->admin, 'admin')
        ->get(route('admin.shipments.show', ['shipment' => $shipment->id]))
        ->assertOk()
        ->getContent();

    $lostPage = $html(($this->makeShipment)(ShipmentStatus::Lost));
    expect($lostPage)->toMatch('/wire:click="confirmLostShipment"\s+wire:confirm=/');

    $rtoPage = $html(($this->makeShipment)(ShipmentStatus::RtoDelivered, SellerOrderStatus::ReturnedToOrigin));
    expect($rtoPage)->toMatch('/wire:click="submitRtoDecision\(\'written_off\'\)"\s+wire:confirm=/');

    $partial = ($this->makeShipment)(ShipmentStatus::OutForDelivery);
    ($this->page)($partial)->call('simulateTransition', 'partial_delivered');
    expect($html($partial))->toMatch('/wire:click="submitPartialDelivery"\s+wire:confirm=/');
});
