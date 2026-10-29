<?php

use App\Models\Admin;
use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Events\SellerOrderReturnedToOrigin;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;
use App\Settlements\Contracts\PayoutServiceInterface;
use App\Settlements\Contracts\SettlementServiceInterface;
use App\Settlements\DTOs\PayoutBatchCreateInput;
use App\Settlements\Enums\LedgerEntryType;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Listeners\ProcessRtoSettlementOnOrderReturned;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Settlements\Models\SellerPayout;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Events\ShipmentDeliveryFailed;
use App\Shipping\Events\ShipmentLost;
use App\Shipping\Events\ShipmentPartialDelivered;
use App\Shipping\Events\ShipmentRtoDelivered;
use App\Shipping\Events\ShipmentRtoInitiated;
use App\Shipping\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('permissions:sync');
    config([
        'returns.rto_shipping_multiplier' => 1.5,
        'settlements.minimum_payout_amount_paise' => 100000,
    ]);

    $this->admin = Admin::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('Super Admin', 'admin'));

    // Approved seller with verified bank details, so payouts are not excluded for other reasons
    $this->sellerProfile = SellerProfile::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => 'approved',
        'bank_account_holder' => 'RTO Seller',
        'bank_account_number' => '123456789012',
        'ifsc' => 'HDFC0001234',
        'reviewed_at' => now()->subDays(10),
        'bank_updated_at' => now()->subDays(12),
    ]);

    $this->customer = User::factory()->create();

    $this->makeSellerOrder = function (string $orderNumber, SellerOrderStatus $status): SellerOrder {
        $order = Order::create([
            'order_number' => $orderNumber,
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

        return SellerOrder::create([
            'order_id' => $order->id,
            'seller_profile_id' => $this->sellerProfile->id,
            'seller_store_name' => $this->sellerProfile->store_name,
            'status' => $status,
            'subtotal' => 100000,
            'discount_total' => 0,
            'grand_total' => 100000,
            'placed_at' => now(),
        ]);
    };

    $this->sellerOrder = ($this->makeSellerOrder)('ORD-RTO-SETTLE-1', SellerOrderStatus::Shipped);

    $this->orderItem = OrderItem::create([
        'seller_order_id' => $this->sellerOrder->id,
        'listing_id' => null,
        'product_variant_id' => null,
        'sku' => 'SKU-RTO-1',
        'upid' => 'UPID-RTO-1',
        'seller_name' => $this->sellerProfile->store_name,
        'title' => 'RTO Item',
        'mrp' => 100000,
        'qty' => 1,
        'unit_price' => 100000,
        'line_total' => 100000,
    ]);

    $this->settlement = SellerOrderSettlement::create([
        'order_id' => $this->sellerOrder->order_id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'status' => SettlementStatus::Pending,
        'gross_amount' => 100000,
        'total_deductions' => 20000,
        'net_payout' => 80000,
        'return_window_days' => 7,
        'hold_until' => null,
    ]);

    // Ledger as written when fees were calculated: sale credit, fee debits, forward shipping debit
    $entries = [
        ['sale', LedgerEntryType::Sale, 100000],
        ['commission', LedgerEntryType::Commission, -10000],
        ['closing_fee', LedgerEntryType::ClosingFee, -2000],
        ['gst_on_fees', LedgerEntryType::GstOnFees, -3000],
        ['shipping_fee', LedgerEntryType::ShippingFee, -5000],
    ];
    foreach ($entries as [$key, $type, $amount]) {
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
});

test('settlements reacts to rto only through the orders event, never to shipping events', function () {
    $settlementListeners = function (string $event): array {
        return collect(Event::getRawListeners()[$event] ?? [])
            ->map(fn ($listener) => is_string($listener) ? $listener : (is_array($listener) ? implode('@', $listener) : 'closure'))
            ->filter(fn (string $listener) => str_starts_with($listener, 'App\\Settlements\\'))
            ->values()
            ->all();
    };

    expect($settlementListeners(SellerOrderReturnedToOrigin::class))->toBe([ProcessRtoSettlementOnOrderReturned::class]);

    foreach ([ShipmentRtoDelivered::class, ShipmentRtoInitiated::class, ShipmentLost::class, ShipmentPartialDelivered::class, ShipmentDeliveryFailed::class] as $shippingEvent) {
        expect($settlementListeners($shippingEvent))->toBe([], "Settlements must not listen to {$shippingEvent}");
    }
});

test('rto delivered by the courier reverses sale and fees, keeps forward shipping and debits rto shipping at 1.5x', function () {
    config(['shipping.fake_simulation_enabled' => true]);

    $shipment = Shipment::create([
        'order_id' => $this->sellerOrder->order_id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::RtoInitiated,
        'awb' => 'SIM-RTO-SETTLE-1',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    // Full chain: Shipping event -> Orders marks ReturnedToOrigin -> SellerOrderReturnedToOrigin -> Settlements
    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call('simulateTransition', 'rto_delivered')
        ->assertHasNoErrors();

    expect($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::ReturnedToOrigin);

    $ledger = SellerLedgerEntry::where('seller_order_id', $this->sellerOrder->id)->get();
    $reversalOf = fn (string $key) => $ledger->firstWhere('source_key', "reversal:settlement:{$this->sellerOrder->id}:{$key}:{$this->orderItem->id}");

    // Sale and every fee reversed
    expect($reversalOf('sale')?->amount)->toBe(-100000)
        ->and($reversalOf('commission')?->amount)->toBe(10000)
        ->and($reversalOf('closing_fee')?->amount)->toBe(2000)
        ->and($reversalOf('gst_on_fees')?->amount)->toBe(3000);

    // Forward shipping kept, RTO shipping = 5000 x 1.5
    expect($reversalOf('shipping_fee'))->toBeNull()
        ->and($ledger->firstWhere('source_key', "settlement:{$this->sellerOrder->id}:rto_shipping")?->amount)->toBe(-7500)
        ->and((int) $ledger->sum('amount'))->toBe(-12500);

    $settlement = $this->settlement->fresh();
    expect($settlement->status)->toBe(SettlementStatus::Eligible)
        ->and($settlement->net_payout)->toBe(-12500)
        ->and($settlement->gross_amount)->toBe(0);
});

test('processing the same rto twice does not double-charge', function () {
    $service = app(SettlementServiceInterface::class);

    $service->processRtoSettlement($this->sellerOrder->id);
    $ledgerCount = SellerLedgerEntry::where('seller_order_id', $this->sellerOrder->id)->count();

    $service->processRtoSettlement($this->sellerOrder->id);

    expect(SellerLedgerEntry::where('seller_order_id', $this->sellerOrder->id)->count())->toBe($ledgerCount)
        ->and((int) SellerLedgerEntry::where('seller_order_id', $this->sellerOrder->id)->sum('amount'))->toBe(-12500)
        ->and($this->settlement->fresh()->net_payout)->toBe(-12500);
});

test('the rto charge is not voided and reduces the seller\'s next payout', function () {
    app(SettlementServiceInterface::class)->processRtoSettlement($this->sellerOrder->id);
    $payouts = app(PayoutServiceInterface::class);

    // Week 1: only the RTO charge is eligible -> nothing to pay, charge stays eligible (carried forward)
    $week1 = $payouts->createWeeklyPayoutBatch(new PayoutBatchCreateInput(scheduledPayoutDate: CarbonImmutable::now()));

    expect($week1->batch)->toBeNull()
        ->and($this->settlement->fresh()->status)->toBe(SettlementStatus::Eligible)
        ->and($this->settlement->fresh()->status)->not->toBe(SettlementStatus::Void);

    // Week 2: a delivered order of 2,000 becomes eligible
    $deliveredOrder = ($this->makeSellerOrder)('ORD-RTO-SETTLE-2', SellerOrderStatus::Delivered);
    $delivered = SellerOrderSettlement::create([
        'order_id' => $deliveredOrder->order_id,
        'seller_order_id' => $deliveredOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'status' => SettlementStatus::Eligible,
        'gross_amount' => 250000,
        'total_deductions' => 50000,
        'net_payout' => 200000,
        'return_window_days' => 7,
        'hold_until' => null,
    ]);

    $week2 = $payouts->createWeeklyPayoutBatch(new PayoutBatchCreateInput(scheduledPayoutDate: CarbonImmutable::now()));

    /** @var SellerPayout $payout */
    $payout = SellerPayout::where('seller_profile_id', $this->sellerProfile->id)->sole();

    expect($week2->batch)->not->toBeNull()
        ->and($payout->net_amount)->toBe(200000 - 12500)
        ->and($this->settlement->fresh()->payout_id)->toBe($payout->id)
        ->and($delivered->fresh()->payout_id)->toBe($payout->id)
        ->and($this->settlement->fresh()->status)->toBe(SettlementStatus::InPayout);
});

test('customer order page says the package was returned to the seller and not charged', function () {
    $this->sellerOrder->update(['status' => SellerOrderStatus::ReturnedToOrigin, 'rto_delivered_at' => now()]);

    $order = Order::find($this->sellerOrder->order_id);

    $this->actingAs($this->customer)
        ->get(route('orders.show', ['order' => $order->order_number]))
        ->assertOk()
        ->assertSee('data-test="rto-notice"', false)
        ->assertSee('Returned to seller.')
        ->assertSee('You will not be charged for it.');
});
