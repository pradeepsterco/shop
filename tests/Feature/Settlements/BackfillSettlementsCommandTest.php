<?php

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
use App\Orders\Events\OrderPlaced;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\OrderService;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Shared\Enums\FeeScheduleStatus;
use App\Shared\Enums\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('settlements:backfill creates settlements and ledger entries idempotently', function () {
    $category = Category::create(['name' => 'Toys', 'slug' => 'toys']);
    $vertical = Vertical::create([
        'category_id' => $category->id,
        'name' => 'Action Figures',
        'slug' => 'action-figures',
        'gst_rate' => 12.0,
        'hsn_code' => '950300',
    ]);
    $brand = Brand::create(['name' => 'Demo Brand', 'slug' => 'demo-brand', 'status' => 'approved']);

    $sellerUser = User::factory()->create(['email' => 'seller-bf@example.com']);
    $seller = SellerProfile::factory()->approved()->create([
        'user_id' => $sellerUser->id,
        'store_name' => 'Backfill Store',
        'pickup_state' => 'Maharashtra',
        'pickup_postal_code' => '400001',
    ]);

    $customer = User::factory()->create(['email' => 'customer-bf@example.com']);
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
        'title' => 'Toy Robot',
        'slug' => 'toy-robot',
        'created_by' => $seller->id,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => 'VAR-BF-001',
        'sku' => 'SKU-BF-001',
        'weight' => 0.50,
        'qc_status' => 'approved',
    ]);

    $listing = Listing::factory()->active()->create([
        'product_variant_id' => $variant->id,
        'seller_profile_id' => $seller->id,
        'mrp' => 150000,
        'selling_price' => 100000,
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

    $orderService = app(OrderService::class);
    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [['listing_id' => $listing->id, 'qty' => 1]],
        expectedTotal: 100000,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    );
    $order = $orderService->place($placeData);
    $sellerOrder = SellerOrder::where('order_id', $order->id)->firstOrFail();

    // Create fee snapshot
    $feeListener = app(CalculateOrderItemFeesOnOrderPlaced::class);
    $feeListener->handle(new OrderPlaced($order->id, $customer->id, $placeData->lines, 'cart'));

    // Delete any settlements created automatically so we can test the backfill command
    SellerLedgerEntry::where('seller_order_id', $sellerOrder->id)->delete();
    SellerOrderSettlement::where('seller_order_id', $sellerOrder->id)->delete();

    // 1. Run backfill
    $this->artisan('settlements:backfill')
        ->expectsOutputToContain('Backfill complete. Created: 1, Already existed/skipped: 0.')
        ->assertSuccessful();

    $settlement = SellerOrderSettlement::where('seller_order_id', $sellerOrder->id)->first();
    expect($settlement)->not->toBeNull();
    expect(SellerLedgerEntry::where('seller_order_id', $sellerOrder->id)->count())->toBe(7);

    // 2. Run backfill again: idempotency test
    $this->artisan('settlements:backfill')
        ->expectsOutputToContain('Backfill complete. Created: 0, Already existed/skipped: 1.')
        ->assertSuccessful();

    expect(SellerOrderSettlement::where('seller_order_id', $sellerOrder->id)->count())->toBe(1);
    expect(SellerLedgerEntry::where('seller_order_id', $sellerOrder->id)->count())->toBe(7);
});
