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
use App\Returns\Contracts\ReturnsLookupInterface;
use App\Returns\Enums\CourierClaimStatus;
use App\Returns\Enums\CourierClaimType;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerOrderSettlement;
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
        'order_number' => 'ORD-LOST-TEST-1',
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
        'items_mrp_total' => 200000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 200000,
        'placed_at' => now(),
    ]);

    $this->sellerOrder = SellerOrder::create([
        'order_id' => $this->order->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'seller_store_name' => $this->sellerProfile->store_name,
        'status' => SellerOrderStatus::Shipped,
        'subtotal' => 200000,
        'discount_total' => 0,
        'grand_total' => 200000,
        'placed_at' => now(),
        'fee_snapshot' => [
            'commission_fee' => 20000,
            'closing_fee' => 2000,
            'shipping_fee' => 6000,
            'payment_gateway_fee' => 4000,
            'gst_on_fees' => 5760,
            'tds_194o' => 2000,
            'tcs_gst' => 2000,
            'net_payout' => 158240,
        ],
    ]);
});

test('when shipment is lost platform absorbs loss and seller settlement continues as delivered', function () {
    $settlement = SellerOrderSettlement::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'status' => SettlementStatus::Pending,
        'gross_amount' => 200000,
        'total_deductions' => 41760,
        'net_payout' => 158240,
        'claimable_credits' => 0,
        'return_window_days' => 7,
        'hold_until' => now()->addDays(7),
        'is_partial_delivery_hold' => false,
    ]);

    $shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::Lost,
        'awb' => 'SIM-LOST-111',
        'declared_value_paise' => 200000,
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    // Admin confirms lost shipment
    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call('confirmLostShipment')
        ->assertHasNoErrors()
        ->assertSee('Shipment confirmed lost');

    // Verify seller settlement continues as delivered (normal net payout, on hold until return window)
    $freshSettlement = $settlement->fresh();
    expect($freshSettlement->status)->toBe(SettlementStatus::OnHold)
        ->and($freshSettlement->net_payout)->toBe(158240)
        ->and($freshSettlement->is_partial_delivery_hold)->toBeFalse();

    // Verify courier claim is recorded against the platform
    /** @var ReturnsLookupInterface $returnsLookup */
    $returnsLookup = app(ReturnsLookupInterface::class);
    $claims = $returnsLookup->getClaimsForShipment($shipment->id);

    expect($claims)->toHaveCount(1)
        ->and($claims[0]->bearer)->toBe('platform')
        ->and($claims[0]->claimType)->toBe(CourierClaimType::LostShipment)
        ->and($claims[0]->claimAmountPaise)->toBe(200000)
        ->and($claims[0]->status)->toBe(CourierClaimStatus::Submitted);
});
