<?php

use App\Fees\Listeners\CalculateOrderItemFeesOnOrderPlaced;
use App\Fees\Models\FeeClosingSlab;
use App\Fees\Models\FeeCommissionSlab;
use App\Fees\Models\FeeSchedule;
use App\Fees\Models\FeeShippingSlab;
use App\Fees\Models\OrderItemFeeSnapshot;
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
use App\Orders\Events\OrderPlaced;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Services\OrderService;
use App\Shared\Enums\FeeScheduleStatus;
use App\Shared\Enums\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function setupMarketplaceEnvironment(): array
{
    $category = Category::create(['name' => 'Toys', 'slug' => 'toys']);
    $vertical = Vertical::create([
        'category_id' => $category->id,
        'name' => 'Action Figures',
        'slug' => 'action-figures',
        'gst_rate' => 12.0,
        'hsn_code' => '950300',
    ]);
    $brand = Brand::create([
        'name' => 'Demo Brand',
        'slug' => 'demo-brand',
        'status' => 'approved',
    ]);

    $sellerUser = User::factory()->create(['email' => 'seller@example.com']);
    $seller = SellerProfile::factory()->approved()->create([
        'user_id' => $sellerUser->id,
        'store_name' => 'Demo Toy Store',
        'store_slug' => 'demo-toy-store',
        'pickup_state' => 'Maharashtra',
        'pickup_postal_code' => '400001',
        'pan' => 'ABCDE1234F',
    ]);

    $customer = User::factory()->create(['email' => 'customer@example.com']);
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Customer Name',
        'phone' => '9876543210',
        'pincode' => '560001', // Bengaluru (National zone)
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
        'title' => 'Action Superhero Figure',
        'slug' => 'action-superhero-figure',
        'created_by' => $seller->id,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => 'VAR-TEST-001',
        'sku' => 'SKU-TEST-001',
        'weight' => 0.50, // 500g
        'qc_status' => 'approved',
    ]);

    $listing = Listing::factory()->active()->create([
        'product_variant_id' => $variant->id,
        'seller_profile_id' => $seller->id,
        'mrp' => 150000,
        'selling_price' => 100000, // ₹1,000.00
        'stock' => 50,
    ]);

    return compact('category', 'vertical', 'seller', 'customer', 'address', 'product', 'variant', 'listing');
}

test('OrderPlaced listener creates fee snapshots with exact numbers', function () {
    $env = setupMarketplaceEnvironment();

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
    FeeCommissionSlab::create([
        'fee_schedule_id' => $schedule->id,
        'min_price' => 0,
        'max_price' => null,
        'rate_bps' => 1000, // 10%
    ]);
    FeeClosingSlab::create([
        'fee_schedule_id' => $schedule->id,
        'min_price' => 0,
        'max_price' => null,
        'fee_amount' => 2000, // ₹20
    ]);
    FeeShippingSlab::create([
        'fee_schedule_id' => $schedule->id,
        'min_weight_grams' => 0,
        'max_weight_grams' => null,
        'zone' => ShippingZone::National,
        'fee_amount' => 5000, // ₹50
        'is_default' => true,
    ]);

    /** @var OrderService $orderService */
    $orderService = app(OrderService::class);

    $placeData = new PlaceOrderData(
        userId: $env['customer']->id,
        addressId: $env['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $env['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 100000,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    );

    // Create Order
    $order = $orderService->place($placeData);
    $orderItem = OrderItem::where('listing_id', $env['listing']->id)->firstOrFail();

    // Trigger listener directly to test logic
    $listener = app(CalculateOrderItemFeesOnOrderPlaced::class);
    $listener->handle(new OrderPlaced($order->id, $env['customer']->id, $placeData->lines, 'cart'));

    $snapshot = OrderItemFeeSnapshot::where('order_item_id', $orderItem->id)->first();
    expect($snapshot)->not->toBeNull()
        ->and($snapshot->selling_price)->toBe(100000)
        ->and($snapshot->commission_amount)->toBe(10000) // ₹100.00
        ->and($snapshot->closing_fee_amount)->toBe(2000)  // ₹20.00
        ->and($snapshot->shipping_fee_amount)->toBe(5000) // ₹50.00
        ->and($snapshot->gst_on_fees_amount)->toBe(3060)  // ₹30.60
        ->and($snapshot->tcs_amount)->toBe(446)          // ₹4.46
        ->and($snapshot->tds_amount)->toBe(100)          // ₹1.00
        ->and($snapshot->net_payout)->toBe(79394)         // ₹793.94
        ->and($snapshot->claimable_credits)->toBe(3606);  // ₹36.06
});

test('listener is idempotent and does not create duplicate snapshots', function () {
    $env = setupMarketplaceEnvironment();

    $schedule = FeeSchedule::create([
        'name' => 'Active Sched',
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

    /** @var OrderService $orderService */
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
    $orderItem = OrderItem::where('listing_id', $env['listing']->id)->firstOrFail();

    $listener = app(CalculateOrderItemFeesOnOrderPlaced::class);
    $event = new OrderPlaced($order->id, $env['customer']->id, $placeData->lines, 'cart');

    // Run listener 3 times
    for ($i = 0; $i < 3; $i++) {
        $listener->handle($event);
    }

    // Exactly one snapshot exists
    expect(OrderItemFeeSnapshot::where('order_item_id', $orderItem->id)->count())->toBe(1);
});

test('order places successfully even when no fee schedule exists', function () {
    $env = setupMarketplaceEnvironment();

    // Ensure NO fee schedules exist
    FeeSchedule::query()->delete();

    /** @var OrderService $orderService */
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

    // Order placement succeeds completely
    $order = $orderService->place($placeData);
    expect($order)->not->toBeNull()
        ->and($order->order_number)->not->toBeEmpty();

    // Listener runs and skips gracefully without throwing exception
    $listener = app(CalculateOrderItemFeesOnOrderPlaced::class);
    $listener->handle(new OrderPlaced($order->id, $env['customer']->id, $placeData->lines, 'cart'));

    // No snapshot created, but no crash
    expect(OrderItemFeeSnapshot::count())->toBe(0);
});

test('fees:backfill command is idempotent and skips orders with no covering schedule', function () {
    $env = setupMarketplaceEnvironment();

    /** @var OrderService $orderService */
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

    // 1. Backfill with NO fee schedule: reports skipped
    FeeSchedule::query()->delete();
    $this->artisan('fees:backfill')
        ->expectsOutputToContain('Skipping')
        ->assertSuccessful();

    expect(OrderItemFeeSnapshot::count())->toBe(0);

    // 2. Add covering fee schedule and run backfill: succeeds
    $schedule = FeeSchedule::create([
        'name' => 'Active Sched',
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

    $this->artisan('fees:backfill')
        ->expectsOutputToContain('Backfill complete. Processed: 1, Skipped: 0.')
        ->assertSuccessful();

    expect(OrderItemFeeSnapshot::count())->toBe(1);

    // 3. Run backfill again: idempotent
    $this->artisan('fees:backfill')
        ->expectsOutputToContain('All order items have fee snapshots. Nothing to backfill.')
        ->assertSuccessful();

    expect(OrderItemFeeSnapshot::count())->toBe(1);
});
