<?php

use App\Fees\Events\FeesCalculated;
use App\Fees\Listeners\CalculateOrderItemFeesOnOrderPlaced;
use App\Fees\Models\FeeClosingSlab;
use App\Fees\Models\FeeCommissionSlab;
use App\Fees\Models\FeeSchedule;
use App\Fees\Models\FeeShippingSlab;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Orders\DTOs\PlaceOrderData;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Events\OrderPlaced;
use App\Orders\Events\SellerOrderCancelled;
use App\Orders\Events\SellerOrderDelivered;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\OrderService;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Events\SettlementEligible;
use App\Settlements\Listeners\CreateSettlementOnFeesCalculated;
use App\Settlements\Listeners\PutSettlementOnHoldOnDelivered;
use App\Settlements\Listeners\VoidSettlementOnOrderCancelled;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Shared\Enums\FeeScheduleStatus;
use App\Shared\Enums\ShippingZone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createTestSettlementEnv(): array
{
    $category = Category::create(['name' => 'Toys', 'slug' => 'toys']);
    $vertical = Vertical::create([
        'category_id' => $category->id,
        'name' => 'Action Figures',
        'slug' => 'action-figures',
        'gst_rate' => 12.0,
        'hsn_code' => '950300',
    ]);
    $brand = Brand::create(['name' => 'Demo Brand', 'slug' => 'demo-brand', 'status' => 'approved']);

    $sellerUser = User::factory()->create(['email' => 'seller-settle@example.com']);
    $seller = SellerProfile::factory()->approved()->create([
        'user_id' => $sellerUser->id,
        'store_name' => 'Settlement Store',
        'store_slug' => 'settlement-store',
        'pickup_state' => 'Maharashtra',
        'pickup_postal_code' => '400001',
        'pan' => 'ABCDE1234F',
    ]);

    $customer = User::factory()->create(['email' => 'customer-settle@example.com']);
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Customer Name',
        'phone' => '9876543210',
        'pincode' => '560001',
        'locality' => 'Koramangala',
        'address_line' => '123 Main St',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'type' => 'home',
        'is_default' => true,
    ]);

    $product = Product::factory()->approved()->create([
        'vertical_id' => $vertical->id,
        'brand_id' => $brand->id,
        'title' => 'Action Figure Hero',
        'slug' => 'action-figure-hero',
        'created_by' => $seller->id,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => 'VAR-ST-001',
        'sku' => 'SKU-ST-001',
        'weight' => 0.50,
        'qc_status' => 'approved',
    ]);

    $listing = Listing::factory()->active()->create([
        'product_variant_id' => $variant->id,
        'seller_profile_id' => $seller->id,
        'mrp' => 150000,
        'selling_price' => 100000, // ₹1,000.00
        'stock' => 50,
    ]);

    $schedule = FeeSchedule::create([
        'name' => 'Active Test Sched',
        'version' => 1,
        'status' => FeeScheduleStatus::Active,
        'effective_from' => now()->subDay(),
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);
    FeeCommissionSlab::create(['fee_schedule_id' => $schedule->id, 'min_price' => 0, 'max_price' => null, 'rate_bps' => 1000]);
    FeeClosingSlab::create(['fee_schedule_id' => $schedule->id, 'min_price' => 0, 'max_price' => null, 'fee_amount' => 2000]);
    FeeShippingSlab::create(['fee_schedule_id' => $schedule->id, 'min_weight_grams' => 0, 'max_weight_grams' => null, 'zone' => ShippingZone::National, 'fee_amount' => 5000, 'is_default' => true]);

    return compact('seller', 'customer', 'address', 'listing', 'schedule');
}

test('normal lifecycle: pending -> on_hold -> eligible after return window', function () {
    Event::fake([SettlementEligible::class]);

    $env = createTestSettlementEnv();
    $orderService = app(OrderService::class);

    $placeData = new PlaceOrderData(
        userId: $env['customer']->id,
        addressId: $env['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [['listing_id' => $env['listing']->id, 'qty' => 1]],
        expectedTotal: 100000,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    );

    $order = $orderService->place($placeData);
    $sellerOrder = SellerOrder::where('order_id', $order->id)->firstOrFail();

    // 1. Fee calculation listener creates fee snapshots & dispatches FeesCalculated
    $feeListener = app(CalculateOrderItemFeesOnOrderPlaced::class);
    $feeListener->handle(new OrderPlaced($order->id, $env['customer']->id, $placeData->lines, 'cart'));

    // Settlements listener automatically creates pending settlement
    $settlement = SellerOrderSettlement::where('seller_order_id', $sellerOrder->id)->first();
    expect($settlement)->not->toBeNull()
        ->and($settlement->status)->toBe(SettlementStatus::Pending)
        ->and($settlement->return_window_days)->toBe(7)
        ->and($settlement->hold_until)->toBeNull();

    // 2. Order Delivered -> PutSettlementOnHoldOnDelivered listener puts settlement on hold
    $deliveredTime = CarbonImmutable::now();
    $deliveredListener = app(PutSettlementOnHoldOnDelivered::class);
    $deliveredListener->handle(new SellerOrderDelivered(
        sellerOrderId: $sellerOrder->id,
        sellerProfileId: $sellerOrder->seller_profile_id,
        timestamp: $deliveredTime,
        actorType: 'admin',
        actorId: 1
    ));

    $settlement->refresh();
    expect($settlement->status)->toBe(SettlementStatus::OnHold)
        ->and($settlement->hold_until)->not->toBeNull()
        ->and($settlement->hold_until->format('Y-m-d H:i:s'))
        ->toBe($deliveredTime->addDays(7)->format('Y-m-d H:i:s'));

    // 3. Immutability: changing config never changes existing return_window_days or hold_until
    config(['settlements.return_window_days' => 30]);
    $settlement->refresh();
    expect($settlement->return_window_days)->toBe(7)
        ->and($settlement->hold_until->format('Y-m-d H:i:s'))
        ->toBe($deliveredTime->addDays(7)->format('Y-m-d H:i:s'));

    // 4. Before hold maturity, settlements:release does not release it
    $this->artisan('settlements:release')->assertSuccessful();
    $settlement->refresh();
    expect($settlement->status)->toBe(SettlementStatus::OnHold);

    // 5. Travel past return window (8 days) -> settlements:release releases into eligible
    CarbonImmutable::setTestNow($deliveredTime->addDays(8));
    try {
        $this->artisan('settlements:release')->assertSuccessful();
        $settlement->refresh();
        expect($settlement->status)->toBe(SettlementStatus::Eligible);
        Event::assertDispatched(SettlementEligible::class);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('out of order: delivered event arrives before fee calculation', function () {
    $env = createTestSettlementEnv();
    $orderService = app(OrderService::class);

    // Prevent settlement creation on order placement by faking FeesCalculated
    Event::fake([FeesCalculated::class]);

    $placeData = new PlaceOrderData(
        userId: $env['customer']->id,
        addressId: $env['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [['listing_id' => $env['listing']->id, 'qty' => 1]],
        expectedTotal: 100000,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    );

    $order = $orderService->place($placeData);
    $sellerOrder = SellerOrder::where('order_id', $order->id)->firstOrFail();

    // Create the fee snapshots directly so fee data exists when queue catches up
    $feeListener = app(CalculateOrderItemFeesOnOrderPlaced::class);
    $feeListener->handle(new OrderPlaced($order->id, $env['customer']->id, $placeData->lines, 'cart'));

    // Transition order to delivered in Orders module
    $deliveredTime = CarbonImmutable::now();
    $sellerOrder->update([
        'status' => SellerOrderStatus::Delivered,
        'delivered_at' => $deliveredTime,
    ]);

    // 1. Delivered event arrives before settlement exists: should execute safely with no error
    $deliveredListener = app(PutSettlementOnHoldOnDelivered::class);
    $deliveredListener->handle(new SellerOrderDelivered(
        sellerOrderId: $sellerOrder->id,
        sellerProfileId: $sellerOrder->seller_profile_id,
        timestamp: $deliveredTime,
        actorType: 'admin',
        actorId: 1
    ));

    // No settlement exists yet because FeesCalculated listener was blocked
    expect(SellerOrderSettlement::where('seller_order_id', $sellerOrder->id)->first())->toBeNull();

    // 2. Now FeesCalculated listener runs
    $settlementListener = app(CreateSettlementOnFeesCalculated::class);
    $settlementListener->handle(new FeesCalculated(
        orderId: $order->id,
        sellerOrderTotals: [$sellerOrder->id => ['total_deductions_paise' => 20606, 'net_payout_paise' => 79394, 'claimable_credits_paise' => 3606]]
    ));

    // Settlement is initialized directly in OnHold status with proper hold_until!
    $settlement = SellerOrderSettlement::where('seller_order_id', $sellerOrder->id)->first();
    expect($settlement)->not->toBeNull()
        ->and($settlement->status)->toBe(SettlementStatus::OnHold)
        ->and($settlement->hold_until)->not->toBeNull();
});

test('out of order: cancelled event arrives before fee calculation', function () {
    $env = createTestSettlementEnv();
    $orderService = app(OrderService::class);

    // Prevent settlement creation on order placement by faking FeesCalculated
    Event::fake([FeesCalculated::class]);

    $placeData = new PlaceOrderData(
        userId: $env['customer']->id,
        addressId: $env['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [['listing_id' => $env['listing']->id, 'qty' => 1]],
        expectedTotal: 100000,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    );

    $order = $orderService->place($placeData);
    $sellerOrder = SellerOrder::where('order_id', $order->id)->firstOrFail();

    // Create fee snapshots so fee data exists
    $feeListener = app(CalculateOrderItemFeesOnOrderPlaced::class);
    $feeListener->handle(new OrderPlaced($order->id, $env['customer']->id, $placeData->lines, 'cart'));

    // Transition order to cancelled in Orders module
    $cancelledTime = CarbonImmutable::now();
    $sellerOrder->update([
        'status' => SellerOrderStatus::Cancelled,
        'cancelled_at' => $cancelledTime,
    ]);

    // 1. Cancelled event arrives before settlement exists: should execute safely
    $cancelListener = app(VoidSettlementOnOrderCancelled::class);
    $cancelListener->handle(new SellerOrderCancelled(
        sellerOrderId: $sellerOrder->id,
        sellerProfileId: $sellerOrder->seller_profile_id,
        timestamp: $cancelledTime,
        actorType: 'seller',
        actorId: 1,
        reason: 'Out of stock'
    ));

    // No settlement yet
    expect(SellerOrderSettlement::where('seller_order_id', $sellerOrder->id)->first())->toBeNull();

    // 2. Now FeesCalculated listener arrives
    $settlementListener = app(CreateSettlementOnFeesCalculated::class);
    $settlementListener->handle(new FeesCalculated(
        orderId: $order->id,
        sellerOrderTotals: [$sellerOrder->id => ['total_deductions_paise' => 20606, 'net_payout_paise' => 79394, 'claimable_credits_paise' => 3606]]
    ));

    // Settlement is initialized directly in Void status with reversals recorded
    $settlement = SellerOrderSettlement::where('seller_order_id', $sellerOrder->id)->first();
    expect($settlement)->not->toBeNull()
        ->and($settlement->status)->toBe(SettlementStatus::Void);

    // Sum of all ledger entries for this void settlement must be 0
    $ledgerSum = (int) SellerLedgerEntry::where('seller_order_id', $sellerOrder->id)->sum('amount');
    expect($ledgerSum)->toBe(0);
});
