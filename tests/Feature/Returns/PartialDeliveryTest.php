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
use App\Returns\Models\UndeliveredItem;
use App\Settlements\Contracts\PayoutServiceInterface;
use App\Settlements\Contracts\SettlementServiceInterface;
use App\Settlements\DTOs\PayoutBatchCreateInput;
use App\Settlements\Enums\LedgerEntryType;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Settlements\Models\SellerPayout;
use App\Shipping\Enums\ShipmentEventType;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use App\Shipping\Models\ShipmentEvent;
use Carbon\CarbonImmutable;
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
        'order_number' => 'ORD-PARTIAL-TEST-1',
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
        'fee_snapshot' => [
            'commission_fee' => 10000,
            'closing_fee' => 2000,
            'shipping_fee' => 5000,
            'payment_gateway_fee' => 2000,
            'gst_on_fees' => 3420,
            'tds_194o' => 1000,
            'tcs_gst' => 1000,
            'net_payout' => 76580,
        ],
    ]);

    $this->item1 = OrderItem::create([
        'seller_order_id' => $this->sellerOrder->id,
        'listing_id' => null,
        'product_variant_id' => null,
        'sku' => 'SKU-ITEM-1',
        'upid' => 'UPID-ITEM-1',
        'seller_name' => $this->sellerProfile->store_name,
        'title' => 'Item 1',
        'mrp' => 50000,
        'qty' => 1,
        'unit_price' => 50000,
        'line_total' => 50000,
    ]);

    $this->item2 = OrderItem::create([
        'seller_order_id' => $this->sellerOrder->id,
        'listing_id' => null,
        'product_variant_id' => null,
        'sku' => 'SKU-ITEM-2',
        'upid' => 'UPID-ITEM-2',
        'seller_name' => $this->sellerProfile->store_name,
        'title' => 'Item 2',
        'mrp' => 50000,
        'qty' => 1,
        'unit_price' => 50000,
        'line_total' => 50000,
    ]);
});

test('partial delivered puts settlement on hold and admin marking releases hold', function () {
    config(['shipping.fake_simulation_enabled' => true]);

    $settlement = SellerOrderSettlement::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'status' => SettlementStatus::Pending,
        'gross_amount' => 100000,
        'total_deductions' => 23420,
        'net_payout' => 76580,
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
        'status' => ShipmentStatus::OutForDelivery,
        'awb' => 'SIM-PARTIAL-777',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    // Advance to partial_delivered
    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call('simulateTransition', 'partial_delivered')
        ->assertHasNoErrors();

    // Verify settlement is on hold with is_partial_delivery_hold = true
    expect($settlement->fresh()->is_partial_delivery_hold)->toBeTrue();

    // Admin reviews and marks Item 1 delivered, Item 2 undelivered
    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->set('selectedDeliveredItems', [(int) $this->item1->id])
        ->set('partialNotes', 'Customer accepted Item 1 only; Item 2 refused')
        ->call('submitPartialDelivery')
        ->assertHasNoErrors()
        ->assertSee('Partial delivery marked and settlement hold released');

    // Settlement hold must be released
    expect($settlement->fresh()->is_partial_delivery_hold)->toBeFalse();

    $freshShipment = $shipment->fresh();
    expect($freshShipment->partial_delivery_data['delivered_item_ids'])->toBe([(int) $this->item1->id])
        ->and($freshShipment->partial_delivery_data['undelivered_item_ids'])->toBe([(int) $this->item2->id]);
});

function partialDeliveryFixture(object $test): array
{
    config(['shipping.fake_simulation_enabled' => true]);

    $settlement = SellerOrderSettlement::create([
        'order_id' => $test->order->id,
        'seller_order_id' => $test->sellerOrder->id,
        'seller_profile_id' => $test->sellerProfile->id,
        'status' => SettlementStatus::Pending,
        'gross_amount' => 100000,
        'total_deductions' => 23420,
        'net_payout' => 76580,
        'claimable_credits' => 0,
        'return_window_days' => 7,
        'hold_until' => now()->addDays(7),
        'is_partial_delivery_hold' => false,
    ]);

    $shipment = Shipment::create([
        'order_id' => $test->order->id,
        'seller_order_id' => $test->sellerOrder->id,
        'seller_profile_id' => $test->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::OutForDelivery,
        'awb' => 'SIM-PARTIAL-'.Str::upper(Str::random(6)),
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    return [$settlement, $shipment];
}

test('admin confirm in the same second as the simulated partial delivery succeeds and releases the hold', function () {
    $this->freezeTime();
    [$settlement, $shipment] = partialDeliveryFixture($this);

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call('simulateTransition', 'partial_delivered')
        ->assertHasNoErrors();

    expect($settlement->fresh()->is_partial_delivery_hold)->toBeTrue();

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->set('selectedDeliveredItems', [(int) $this->item1->id])
        ->call('submitPartialDelivery')
        ->assertSee('Partial delivery marked and settlement hold released')
        ->assertDontSee('data-test="partial-delivery-card"', false);

    expect($settlement->fresh()->is_partial_delivery_hold)->toBeFalse();

    $events = ShipmentEvent::where('shipment_id', $shipment->id)->get();
    $freshShipment = $shipment->fresh();

    // One courier partial_delivered event, one admin resolution event; no second partial_delivered
    expect($events)->toHaveCount(2)
        ->and($events->where('event_type', ShipmentEventType::CourierUpdate)->where('status', ShipmentStatus::PartialDelivered))->toHaveCount(1)
        ->and($events->where('event_type', ShipmentEventType::PartialDeliveryResolved))->toHaveCount(1)
        ->and($freshShipment->status)->toBe(ShipmentStatus::PartialDelivered)
        ->and($freshShipment->partial_delivery_data['resolved_by_admin_id'])->toBe($this->admin->id);
});

test('if releasing the settlement hold fails, the partial delivery resolution is rolled back', function () {
    [$settlement, $shipment] = partialDeliveryFixture($this);

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call('simulateTransition', 'partial_delivered');

    $realSettlements = app(SettlementServiceInterface::class);
    $failing = Mockery::mock(SettlementServiceInterface::class);
    $failing->shouldReceive('releasePartialDeliveryHold')->andThrow(new RuntimeException('ledger unavailable'));
    app()->instance(SettlementServiceInterface::class, $failing);

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->set('selectedDeliveredItems', [(int) $this->item1->id])
        ->call('submitPartialDelivery')
        ->assertSee('Could not confirm delivered items. Nothing was changed and the settlement hold is still in place.')
        ->assertSee('ledger unavailable');

    app()->instance(SettlementServiceInterface::class, $realSettlements);

    expect($settlement->fresh()->is_partial_delivery_hold)->toBeTrue()
        ->and($shipment->fresh()->partial_delivery_data['resolved_at'] ?? null)->toBeNull()
        ->and(ShipmentEvent::where('shipment_id', $shipment->id)->where('event_type', ShipmentEventType::PartialDeliveryResolved)->count())->toBe(0);
});

test('partial delivery holds the settlement until admin marks delivered items, then releases it and records undelivered items', function () {
    [$settlement, $shipment] = partialDeliveryFixture($this);
    $settlements = app(SettlementServiceInterface::class);

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call('simulateTransition', 'partial_delivered');

    // Held: no amount of waiting releases it while admin has not reviewed the items
    $this->travel(30)->days();
    $settlements->releaseMaturedSettlements();
    expect($settlement->fresh()->is_partial_delivery_hold)->toBeTrue()
        ->and($settlement->fresh()->status)->not->toBe(SettlementStatus::Eligible);

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->set('selectedDeliveredItems', [(int) $this->item1->id])
        ->call('submitPartialDelivery')
        ->assertSee('Partial delivery marked and settlement hold released');

    // Released into the normal return window, starting now
    $released = $settlement->fresh();
    expect($released->is_partial_delivery_hold)->toBeFalse()
        ->and($released->status)->toBe(SettlementStatus::OnHold)
        ->and($released->hold_until->format('Y-m-d H:i:s'))->toBe(now()->addDays(7)->format('Y-m-d H:i:s'));

    // Undelivered item recorded for phase B missing-item handling; delivered item is not
    $undelivered = UndeliveredItem::where('shipment_id', $shipment->id)->get();
    expect($undelivered)->toHaveCount(1)
        ->and($undelivered->first()->order_item_id)->toBe((int) $this->item2->id)
        ->and($undelivered->first()->seller_order_id)->toBe($this->sellerOrder->id)
        ->and($undelivered->first()->status)->toBe(UndeliveredItem::STATUS_PENDING)
        ->and($undelivered->first()->recorded_by_admin_id)->toBe($this->admin->id);

    $settlements->releaseMaturedSettlements();
    expect($settlement->fresh()->status)->toBe(SettlementStatus::OnHold);

    $this->travel(8)->days();
    $settlements->releaseMaturedSettlements();
    expect($settlement->fresh()->status)->toBe(SettlementStatus::Eligible);
});

test('a matured on-hold settlement is not released while a partial delivery hold is set', function () {
    [$settlement] = partialDeliveryFixture($this);
    $settlement->update([
        'status' => SettlementStatus::OnHold,
        'hold_until' => now()->subDay(),
        'is_partial_delivery_hold' => true,
    ]);

    app(SettlementServiceInterface::class)->releaseMaturedSettlements();

    expect($settlement->fresh()->status)->toBe(SettlementStatus::OnHold);
});

test('partial delivery reverses sale and fees of undelivered items so the seller is paid for delivered items only', function () {
    config(['settlements.minimum_payout_amount_paise' => 10000]);
    $this->sellerProfile->update([
        'status' => 'approved',
        'bank_account_holder' => 'Partial Seller',
        'bank_account_number' => '123456789012',
        'ifsc' => 'HDFC0001234',
        'reviewed_at' => now()->subDays(10),
        'bank_updated_at' => now()->subDays(12),
    ]);

    [$settlement, $shipment] = partialDeliveryFixture($this);

    // Per item (50,000 sale): fees 7,830 + allocated forward shipping 2,500 => item net 39,670
    $perItem = [
        ['sale', LedgerEntryType::Sale, 50000],
        ['commission', LedgerEntryType::Commission, -5000],
        ['closing_fee', LedgerEntryType::ClosingFee, -1000],
        ['gst_on_fees', LedgerEntryType::GstOnFees, -1080],
        ['tcs', LedgerEntryType::Tcs, -250],
        ['tds', LedgerEntryType::Tds, -500],
        ['shipping_fee', LedgerEntryType::ShippingFee, -2500],
    ];
    foreach ([$this->item1, $this->item2] as $item) {
        foreach ($perItem as [$key, $type, $amount]) {
            SellerLedgerEntry::create([
                'seller_profile_id' => $this->sellerProfile->id,
                'seller_order_id' => $this->sellerOrder->id,
                'order_item_id' => $item->id,
                'type' => $type,
                'amount' => $amount,
                'description' => $type->label(),
                'source_key' => "settlement:{$this->sellerOrder->id}:{$key}:{$item->id}",
                'created_at' => now(),
            ]);
        }
    }
    $settlement->update(['gross_amount' => 100000, 'net_payout' => 79340, 'total_deductions' => 20660]);

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call('simulateTransition', 'partial_delivered');

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->set('selectedDeliveredItems', [(int) $this->item1->id])
        ->call('submitPartialDelivery')
        ->assertSee('Partial delivery marked and settlement hold released');

    // Item 2: sale and every fee reversed, its forward shipping kept; item 1 untouched
    $reversals = SellerLedgerEntry::where('seller_order_id', $this->sellerOrder->id)
        ->where('type', LedgerEntryType::Reversal)->get();
    expect($reversals)->toHaveCount(6)
        ->and($reversals->pluck('order_item_id')->unique()->values()->all())->toBe([(int) $this->item2->id])
        ->and($reversals->firstWhere('source_key', "reversal:settlement:{$this->sellerOrder->id}:shipping_fee:{$this->item2->id}"))->toBeNull();

    $deliveredNet = 39670;
    $undeliveredForwardShipping = 2500;

    $fresh = $settlement->fresh();
    expect($fresh->gross_amount)->toBe(50000)
        ->and($fresh->net_payout)->toBe($deliveredNet - $undeliveredForwardShipping)
        ->and($fresh->total_deductions)->toBe(50000 - ($deliveredNet - $undeliveredForwardShipping))
        ->and((int) SellerLedgerEntry::where('seller_order_id', $this->sellerOrder->id)->sum('amount'))->toBe($fresh->net_payout);

    // Orders: partially delivered, COD expected for the delivered item only; parent order completes
    $sellerOrder = $this->sellerOrder->fresh();
    $order = $this->order->fresh();
    expect($sellerOrder->status)->toBe(SellerOrderStatus::PartiallyDelivered)
        ->and($sellerOrder->expected_cod_amount_paise)->toBe(50000)
        ->and($order->status)->toBe(OrderStatus::Completed)
        ->and($order->payment_status)->toBe(PaymentStatus::Completed);

    // After the return window the seller is paid exactly that amount
    $this->travel(8)->days();
    app(SettlementServiceInterface::class)->releaseMaturedSettlements();
    app(PayoutServiceInterface::class)->createWeeklyPayoutBatch(new PayoutBatchCreateInput(scheduledPayoutDate: CarbonImmutable::now()));

    expect(SellerPayout::where('seller_profile_id', $this->sellerProfile->id)->sole()->net_amount)
        ->toBe($deliveredNet - $undeliveredForwardShipping);
});

test('confirming with every item delivered marks the order delivered without reversals', function () {
    [$settlement, $shipment] = partialDeliveryFixture($this);

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call('simulateTransition', 'partial_delivered');

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->set('selectedDeliveredItems', [(int) $this->item1->id, (int) $this->item2->id])
        ->call('submitPartialDelivery')
        ->assertSee('Partial delivery marked and settlement hold released');

    expect($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Delivered)
        ->and($this->sellerOrder->fresh()->expected_cod_amount_paise)->toBeNull()
        ->and(UndeliveredItem::count())->toBe(0)
        ->and($settlement->fresh()->net_payout)->toBe(76580);
});

test('customer order page shows which items of a partially delivered package arrived', function () {
    [, $shipment] = partialDeliveryFixture($this);

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call('simulateTransition', 'partial_delivered');

    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->set('selectedDeliveredItems', [(int) $this->item1->id])
        ->call('submitPartialDelivery');

    $html = $this->actingAs($this->customer)
        ->get(route('orders.show', ['order' => $this->order->order_number]))
        ->assertOk()
        ->assertSee('Partially delivered.')
        ->assertSee('You are only charged for delivered items.')
        ->getContent();

    // Item 1 delivered, item 2 not: badges appear in item order
    expect(substr_count($html, 'data-test="item-delivered"'))->toBe(1)
        ->and(substr_count($html, 'data-test="item-not-delivered"'))->toBe(1)
        ->and(strpos($html, 'Item 1'))->toBeLessThan(strpos($html, 'data-test="item-delivered"'))
        ->and(strpos($html, 'Item 2'))->toBeLessThan(strpos($html, 'data-test="item-not-delivered"'))
        ->and(strpos($html, 'data-test="item-delivered"'))->toBeLessThan(strpos($html, 'Item 2'));
});
