<?php

use App\Coupons\Contracts\CouponRedemptionInterface;
use App\Coupons\DTOs\RedemptionReleaseResult;
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
use App\Returns\Enums\CourierClaimType;
use App\Returns\Models\CourierClaim;
use App\Returns\Models\PlatformLoss;
use App\Settlements\Contracts\PayoutServiceInterface;
use App\Settlements\Contracts\SettlementServiceInterface;
use App\Settlements\DTOs\PayoutBatchCreateInput;
use App\Settlements\Enums\LedgerEntryType;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Settlements\Models\SellerPayout;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('permissions:sync');
    config([
        'returns.courier_loss_bearer' => 'platform',
        'settlements.minimum_payout_amount_paise' => 50000,
    ]);

    $this->admin = Admin::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('Super Admin', 'admin'));

    $this->sellerProfile = SellerProfile::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => 'approved',
        'bank_account_holder' => 'Lost Seller',
        'bank_account_number' => '123456789012',
        'ifsc' => 'HDFC0001234',
        'reviewed_at' => now()->subDays(10),
        'bank_updated_at' => now()->subDays(12),
    ]);

    $this->customer = User::factory()->create();
    $this->order = Order::create([
        'order_number' => 'ORD-LOST-1',
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
        'items_mrp_total' => 100000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 100000,
        'placed_at' => now(),
    ]);

    $this->sellerOrder = SellerOrder::create([
        'order_id' => $this->order->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'seller_store_name' => $this->sellerProfile->store_name,
        'status' => SellerOrderStatus::Shipped,
        'subtotal' => 100000,
        'discount_total' => 0,
        'grand_total' => 100000,
        'placed_at' => now(),
    ]);

    $this->orderItem = OrderItem::create([
        'seller_order_id' => $this->sellerOrder->id,
        'listing_id' => null,
        'product_variant_id' => null,
        'sku' => 'SKU-LOST-1',
        'upid' => 'UPID-LOST-1',
        'seller_name' => $this->sellerProfile->store_name,
        'title' => 'Lost Item',
        'mrp' => 100000,
        'qty' => 1,
        'unit_price' => 100000,
        'line_total' => 100000,
    ]);

    // Settlement and ledger as written when fees were calculated: net 80,000 = 100,000 - 20,000 fees
    $this->settlement = SellerOrderSettlement::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'status' => SettlementStatus::Pending,
        'gross_amount' => 100000,
        'total_deductions' => 20000,
        'net_payout' => 80000,
        'return_window_days' => 7,
        'hold_until' => null,
    ]);

    foreach ([
        ['sale', LedgerEntryType::Sale, 100000],
        ['commission', LedgerEntryType::Commission, -10000],
        ['closing_fee', LedgerEntryType::ClosingFee, -2000],
        ['gst_on_fees', LedgerEntryType::GstOnFees, -3000],
        ['shipping_fee', LedgerEntryType::ShippingFee, -5000],
    ] as [$key, $type, $amount]) {
        SellerLedgerEntry::create([
            'seller_profile_id' => $this->sellerProfile->id,
            'seller_order_id' => $this->sellerOrder->id,
            'order_item_id' => $this->orderItem->id,
            'type' => $type,
            'amount' => $amount,
            'description' => $type->label(),
            'source_key' => "settlement:{$this->sellerOrder->id}:{$key}:{$this->orderItem->id}",
            'created_at' => now(),
        ]);
    }

    $this->shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::Lost,
        'awb' => 'SIM-LOST-SETTLE',
        'is_cod' => true,
        'cod_amount_paise' => 100000,
        'declared_value_paise' => 100000,
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    $this->confirmLost = fn () => Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $this->shipment->id])
        ->call('confirmLostShipment');
});

test('confirming a lost shipment keeps the seller ledger as delivered and records claim and platform loss separately', function () {
    $this->freezeTime();

    ($this->confirmLost)()->assertSee('Shipment confirmed lost');

    // Seller ledger: sale and fees stand, nothing reversed, no extra charges
    $ledger = SellerLedgerEntry::where('seller_order_id', $this->sellerOrder->id)->get();
    expect($ledger)->toHaveCount(5)
        ->and($ledger->where('type', LedgerEntryType::Reversal))->toHaveCount(0)
        ->and((int) $ledger->sum('amount'))->toBe(80000);

    // Settlement continues as delivered: on hold for the normal return window
    $settlement = $this->settlement->fresh();
    expect($settlement->status)->toBe(SettlementStatus::OnHold)
        ->and($settlement->net_payout)->toBe(80000)
        ->and($settlement->hold_until->format('Y-m-d H:i:s'))->toBe(now()->addDays(7)->format('Y-m-d H:i:s'));

    // Courier claim for the declared value; platform loss recorded separately for the payout it funds
    $claim = CourierClaim::where('shipment_id', $this->shipment->id)->sole();
    $loss = PlatformLoss::where('shipment_id', $this->shipment->id)->sole();

    expect($claim->claim_type)->toBe(CourierClaimType::LostShipment)
        ->and($claim->claim_amount_paise)->toBe(100000)
        ->and($claim->bearer)->toBe('platform')
        ->and($loss->loss_type)->toBe(PlatformLoss::TYPE_LOST_SHIPMENT)
        ->and($loss->loss_amount_paise)->toBe(80000)
        ->and($loss->declared_value_paise)->toBe(100000)
        ->and($loss->courier_claim_id)->toBe($claim->id);
});

test('the customer is never charged for a lost shipment', function () {
    ($this->confirmLost)();

    $order = $this->order->fresh();

    expect($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::LostInTransit)
        ->and($order->payment_status)->toBe(PaymentStatus::Cancelled)
        ->and($order->payment_status)->not->toBe(PaymentStatus::Completed)
        ->and($order->status)->toBe(OrderStatus::Cancelled);

    $this->actingAs($this->customer)
        ->get(route('orders.show', ['order' => $this->order->order_number]))
        ->assertOk()
        ->assertSee('This package was lost in transit by the courier.')
        ->assertSee('You will not be charged for it.');
});

test('the seller is paid normally after the return window', function () {
    ($this->confirmLost)();

    $this->travel(8)->days();
    app(SettlementServiceInterface::class)->releaseMaturedSettlements();
    expect($this->settlement->fresh()->status)->toBe(SettlementStatus::Eligible);

    $payouts = app(PayoutServiceInterface::class);
    $batch = $payouts->createWeeklyPayoutBatch(new PayoutBatchCreateInput(scheduledPayoutDate: CarbonImmutable::now()));

    /** @var SellerPayout $payout */
    $payout = SellerPayout::where('seller_profile_id', $this->sellerProfile->id)->sole();
    expect($batch->batch)->not->toBeNull()
        ->and($payout->net_amount)->toBe(80000)
        ->and($payout->carried_balance_deduction)->toBe(0);

    $payouts->markPayoutPaid($payout->id, 'UTR-LOST-1');

    // Sale, fees and payout settle the seller's ledger for this order to zero
    expect($this->settlement->fresh()->status)->toBe(SettlementStatus::Paid)
        ->and((int) SellerLedgerEntry::where('seller_profile_id', $this->sellerProfile->id)->sum('amount'))->toBe(0)
        ->and(SellerLedgerEntry::where('payout_id', $payout->id)->sole()->amount)->toBe(-80000);
});

test('a lost shipment cannot be confirmed twice', function () {
    ($this->confirmLost)()->assertSee('Shipment confirmed lost');
    ($this->confirmLost)()->assertSee('This lost shipment has already been confirmed.');

    expect(CourierClaim::where('shipment_id', $this->shipment->id)->count())->toBe(1)
        ->and(PlatformLoss::where('shipment_id', $this->shipment->id)->count())->toBe(1);
});

test('only a shipment reported lost by the courier can be confirmed lost', function () {
    $this->shipment->update(['status' => ShipmentStatus::InTransit]);

    ($this->confirmLost)()->assertSee('Only a shipment the courier reported as Lost can be confirmed lost.');

    expect(CourierClaim::count())->toBe(0)
        ->and(PlatformLoss::count())->toBe(0)
        ->and($this->settlement->fresh()->status)->toBe(SettlementStatus::Pending)
        ->and($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Shipped);
});

test('a lost order restores coupon usage when the rto restore switch is on', function () {
    config(['coupons.restore_usage_on_rto' => true]);
    $this->sellerOrder->update(['coupon_discount_total' => 5000]);

    $coupons = Mockery::mock(CouponRedemptionInterface::class);
    $coupons->shouldReceive('release')
        ->once()
        ->with('ORD-LOST-1', 5000)
        ->andReturn(new RedemptionReleaseResult(success: true, releasedAmount: 5000, cumulativeReleasedAmount: 5000, status: 'released'));
    app()->instance(CouponRedemptionInterface::class, $coupons);

    ($this->confirmLost)()->assertSee('Shipment confirmed lost');
});

test('a lost order keeps coupon usage when the rto restore switch is off', function () {
    config(['coupons.restore_usage_on_rto' => false]);
    $this->sellerOrder->update(['coupon_discount_total' => 5000]);

    $coupons = Mockery::spy(CouponRedemptionInterface::class);
    app()->instance(CouponRedemptionInterface::class, $coupons);

    ($this->confirmLost)()->assertSee('Shipment confirmed lost');

    $coupons->shouldNotHaveReceived('release');
});
