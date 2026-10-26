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
use App\Orders\Services\OrderService;
use App\Services\UpidGenerator;
use Illuminate\Support\Str;
use Livewire\Livewire;

function setupOrderAuthContext(): array
{
    $brand = Brand::create(['name' => 'AuthToy', 'slug' => 'authtoy-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Toys', 'slug' => 'toys-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Puzzles', 'slug' => 'puzzles-'.uniqid(), 'hsn_code' => '9503', 'gst_rate' => 12.0]);

    // Seller 1 (Owner)
    $seller1User = User::factory()->create();
    $seller1 = SellerProfile::factory()->create([
        'user_id' => $seller1User->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Owner Store',
        'gstin' => '27AAACA1111A1Z1',
        'pickup_state' => 'Maharashtra',
    ]);

    // Seller 2 (Attacker / Other Seller)
    $seller2User = User::factory()->create();
    $seller2 = SellerProfile::factory()->create([
        'user_id' => $seller2User->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Other Store',
        'gstin' => '29AAACB2222B1Z2',
        'pickup_state' => 'Karnataka',
    ]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Wooden Puzzle',
        'slug' => 'puzzle-'.uniqid(),
        'description' => 'Mind game',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    ProductImage::create([
        'product_id' => $product->id,
        'path' => 'products/puzzle.jpg',
        'is_primary' => true,
        'status' => ProductImageStatus::Live,
        'sort_order' => 1,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['pieces' => '100'],
        'attribute_snapshot' => ['pieces' => ['value' => '100', 'label' => '100 Pcs']],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $listing = Listing::create([
        'seller_profile_id' => $seller1->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SKU-PUZZLE-'.uniqid(),
        'mrp' => 100000,
        'selling_price' => 79900,
        'stock' => 5,
        'status' => ListingStatus::Active,
    ]);

    // Customer 1 (Owner)
    $customer1 = User::factory()->create();
    $address1 = CustomerAddress::create([
        'user_id' => $customer1->id,
        'name' => 'Owner Customer',
        'phone' => '9876543210',
        'pincode' => '400001',
        'locality' => 'Colaba',
        'address_line' => '1 Main St',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'type' => 'home',
        'is_default' => true,
    ]);

    // Customer 2 (Attacker / Other Customer)
    $customer2 = User::factory()->create();

    $orderService = app(OrderService::class);
    $order = $orderService->place(new PlaceOrderData(
        userId: $customer1->id,
        addressId: $address1->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $listing->id, 'qty' => 1],
        ],
        expectedTotal: 79900,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    $sellerOrder = $order->sellerOrders->first();

    return compact('seller1User', 'seller1', 'seller2User', 'seller2', 'customer1', 'customer2', 'order', 'sellerOrder');
}

test('customer order authorization: another customer gets 403 on order view and success page', function () {
    $ctx = setupOrderAuthContext();

    // Customer 1 (Owner) can view
    $this->actingAs($ctx['customer1']);
    Livewire::test('pages::storefront.order-show', ['order' => $ctx['order']->order_number])
        ->assertOk();
    Livewire::test('pages::storefront.order-success', ['order' => $ctx['order']->order_number])
        ->assertOk();

    // Customer 2 (Unrelated) gets 403
    $this->actingAs($ctx['customer2']);
    Livewire::test('pages::storefront.order-show', ['order' => $ctx['order']->order_number])
        ->assertForbidden();
    Livewire::test('pages::storefront.order-success', ['order' => $ctx['order']->order_number])
        ->assertForbidden();
});

test('customer order authorization: another customer cannot execute cancel actions', function () {
    $ctx = setupOrderAuthContext();

    // 1. promptCancel
    $this->actingAs($ctx['customer1']);
    $c1 = Livewire::test('pages::storefront.order-show', ['order' => $ctx['order']->order_number]);

    $this->actingAs($ctx['customer2']);
    $c1->call('promptCancel', $ctx['sellerOrder']->id)->assertForbidden();

    // 2. confirmCancel
    $this->actingAs($ctx['customer1']);
    $c2 = Livewire::test('pages::storefront.order-show', ['order' => $ctx['order']->order_number]);

    $this->actingAs($ctx['customer2']);
    $c2->call('confirmCancel')->assertForbidden();
});

test('seller order authorization: another seller gets 403 on view and seller actions', function () {
    $ctx = setupOrderAuthContext();

    // Seller 1 (Owner) can view
    $this->actingAs($ctx['seller1User']);
    Livewire::test('pages::seller.orders-show', ['sellerOrder' => $ctx['sellerOrder']->id])
        ->assertOk();

    // Seller 2 (Other Seller) gets 403 on view
    $this->actingAs($ctx['seller2User']);
    Livewire::test('pages::seller.orders-show', ['sellerOrder' => $ctx['sellerOrder']->id])
        ->assertForbidden();

    // Seller 2 gets 403 on confirmOrder action
    $this->actingAs($ctx['seller1User']);
    $c1 = Livewire::test('pages::seller.orders-show', ['sellerOrder' => $ctx['sellerOrder']->id]);
    $this->actingAs($ctx['seller2User']);
    $c1->call('confirmOrder')->assertForbidden();

    // Seller 2 gets 403 on markPacked action
    $this->actingAs($ctx['seller1User']);
    $c2 = Livewire::test('pages::seller.orders-show', ['sellerOrder' => $ctx['sellerOrder']->id]);
    $this->actingAs($ctx['seller2User']);
    $c2->call('markPacked')->assertForbidden();

    // Seller 2 gets 403 on confirmCancel action
    $this->actingAs($ctx['seller1User']);
    $c3 = Livewire::test('pages::seller.orders-show', ['sellerOrder' => $ctx['sellerOrder']->id]);
    $this->actingAs($ctx['seller2User']);
    $c3->call('confirmCancel')->assertForbidden();
});
