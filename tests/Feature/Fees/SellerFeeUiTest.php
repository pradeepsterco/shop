<?php

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
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\OrderService;
use App\Shared\Enums\FeeScheduleStatus;
use App\Shared\Enums\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('seller can view fee calculator page and receive live quote', function () {
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

    $sellerUser = User::factory()->create();
    $seller = SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id]);

    $this->actingAs($sellerUser)
        ->get(route('seller.fees.calculator'))
        ->assertOk()
        ->assertSee('Seller Fee & Payout Calculator', escape: false);

    // Test livewire reactivity
    Livewire::actingAs($sellerUser)
        ->test('pages::seller.fee-calculator')
        ->set('price', '1000')
        ->set('productGstRate', '12')
        ->set('weightGrams', '500')
        ->set('zone', 'national')
        ->assertSee('₹793.94') // Net payout
        ->assertSee('₹36.06'); // Claimable credits
});

test('seller order details displays item and package fee snapshots', function () {
    $category = Category::create(['name' => 'Toys', 'slug' => 'toys']);
    $vertical = Vertical::create(['category_id' => $category->id, 'name' => 'Puzzles', 'slug' => 'puzzles', 'gst_rate' => 12.0, 'hsn_code' => '9503']);
    $brand = Brand::create(['name' => 'Brand A', 'slug' => 'brand-a', 'status' => 'approved']);

    $sellerUser = User::factory()->create();
    $seller = SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id, 'pickup_state' => 'Maharashtra', 'pickup_postal_code' => '400001']);

    $customer = User::factory()->create();
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Buyer',
        'phone' => '9876543210',
        'pincode' => '560001',
        'locality' => 'Koramangala',
        'address_line' => '123 St',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'type' => 'home',
        'is_default' => true,
    ]);

    $product = Product::factory()->approved()->create(['vertical_id' => $vertical->id, 'brand_id' => $brand->id, 'created_by' => $seller->id]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'upid' => 'VAR-PUZ-1', 'sku' => 'SKU-PUZ-1', 'weight' => 0.50, 'qc_status' => 'approved']);
    $listing = Listing::factory()->active()->create(['product_variant_id' => $variant->id, 'seller_profile_id' => $seller->id, 'mrp' => 150000, 'selling_price' => 100000, 'stock' => 10]);

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

    $orderService = app(OrderService::class);
    $order = $orderService->place(new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [['listing_id' => $listing->id, 'qty' => 1]],
        expectedTotal: 100000,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    $sellerOrder = SellerOrder::where('order_id', $order->id)->where('seller_profile_id', $seller->id)->firstOrFail();
    $orderItem = OrderItem::where('seller_order_id', $sellerOrder->id)->firstOrFail();

    // Verify snapshot was automatically created by OrderPlaced listener
    $snapshot = OrderItemFeeSnapshot::where('order_item_id', $orderItem->id)->firstOrFail();
    expect($snapshot->net_payout)->toBe(79394)
        ->and($snapshot->total_deductions)->toBe(20606);

    // Seller views order
    $this->actingAs($sellerUser)
        ->get(route('seller.orders.show', $sellerOrder))
        ->assertOk()
        ->assertSee('Net Item Payout: ₹793.94')
        ->assertSee('Net Bank Payout')
        ->assertSee('₹793.94')
        ->assertSee('₹36.06');

    // Customer or another seller cannot see this seller's order
    $otherSellerUser = User::factory()->create();
    SellerProfile::factory()->approved()->create(['user_id' => $otherSellerUser->id]);

    $this->actingAs($otherSellerUser)
        ->get(route('seller.orders.show', $sellerOrder))
        ->assertForbidden();
});
