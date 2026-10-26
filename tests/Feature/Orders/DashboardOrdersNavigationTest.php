<?php

use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Orders\DTOs\PlaceOrderData;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Services\OrderService;
use App\Services\UpidGenerator;
use Illuminate\Support\Str;

function setupDashboardNavContext(): array
{
    $brand = Brand::create(['name' => 'NavBrand', 'slug' => 'navbrand-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Toys', 'slug' => 'toys-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Robots', 'slug' => 'robots-'.uniqid(), 'hsn_code' => '9503', 'gst_rate' => 18.0]);

    // Seller
    $sellerUser = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $sellerUser->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Super Toys',
        'gstin' => '27SUPER1111A1Z1',
        'pickup_state' => 'Maharashtra',
    ]);

    $prod = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Cosmic Bot',
        'slug' => 'cosmic-bot-'.uniqid(),
        'description' => 'A bot',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    ProductImage::create(['product_id' => $prod->id, 'path' => 'products/bot.jpg', 'is_primary' => true, 'status' => ProductImageStatus::Live, 'sort_order' => 1]);
    $var = ProductVariant::create([
        'product_id' => $prod->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'Silver'],
        'attribute_snapshot' => ['color' => ['value' => 'Silver', 'label' => 'Silver']],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    $listing = Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $var->id,
        'sku' => 'SKU-COSMIC-BOT',
        'mrp' => 150000,
        'selling_price' => 99900,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    // Customer
    $customer = User::factory()->create();
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Aarav Patel',
        'phone' => '9822001122',
        'pincode' => '400050',
        'locality' => 'Bandra',
        'address_line' => '10 Hill Road',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'type' => 'home',
        'is_default' => true,
    ]);

    $orderService = app(OrderService::class);
    $order = $orderService->place(new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $listing->id, 'qty' => 1],
        ],
        expectedTotal: 99900,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    $sellerOrder = $order->sellerOrders->first();

    return compact('sellerUser', 'seller', 'customer', 'address', 'order', 'sellerOrder');
}

test('customer dashboard sidebar contains My Orders and Addresses links', function () {
    $ctx = setupDashboardNavContext();

    $response = $this->actingAs($ctx['customer'])->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('My Orders');
    $response->assertSee(route('orders.index'));
    $response->assertSee('Addresses');
    $response->assertSee(route('addresses.index'));
});

test('customer orders list and details open inside customer dashboard layout with package total', function () {
    $ctx = setupDashboardNavContext();

    $this->actingAs($ctx['customer']);

    // Order list renders in app dashboard layout
    $listResponse = $this->get(route('orders.index'));
    $listResponse->assertOk();
    $listResponse->assertSee('My Orders');
    $listResponse->assertSee($ctx['order']->order_number);
    $listResponse->assertSee('Cosmic Bot');

    // Order details renders in app dashboard layout and shows per-package total
    $detailResponse = $this->get(route('orders.show', ['order' => $ctx['order']->order_number]));
    $detailResponse->assertOk();
    $detailResponse->assertSee($ctx['order']->order_number);
    $detailResponse->assertSee('Package from');
    $detailResponse->assertSee('Super Toys');
    $detailResponse->assertSee('Package Total (1 unit):');
    $detailResponse->assertSee('₹999');
    $detailResponse->assertSee('Price Details');
});

test('seller dashboard sidebar contains Orders link with placed orders count badge', function () {
    $ctx = setupDashboardNavContext();

    $this->actingAs($ctx['sellerUser']);

    // 1 Placed order -> shows badge with "1"
    $response1 = $this->get(route('seller.dashboard'));
    $response1->assertOk();
    $response1->assertSee('Orders');
    $response1->assertSee(route('seller.orders.index'));
    $response1->assertSee('1'); // Placed order badge count

    // Transition order to confirmed -> badge count becomes 0
    $ctx['sellerOrder']->update(['status' => SellerOrderStatus::Confirmed]);

    $response2 = $this->get(route('seller.dashboard'));
    $response2->assertOk();
    $response2->assertSee('Orders');
    // Badge span is not rendered when count is 0
    $response2->assertDontSee('bg-amber-500 px-2 py-0.5 text-[10px] font-bold text-white');
});
