<?php

use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Admin;
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
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Services\OrderService;
use App\Services\UpidGenerator;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Artisan::call('permissions:sync');
});

function createAdminWithPermissions(array $permissions): Admin
{
    $admin = Admin::factory()->create();
    $role = Role::create([
        'name' => 'Role_'.uniqid(),
        'guard_name' => 'admin',
    ]);
    $role->syncPermissions($permissions);
    $admin->assignRole($role);

    return $admin;
}

function setupAdminOrderInsightContext(): array
{
    $customer = User::factory()->create([
        'name' => 'John Customer Doe',
        'email' => 'john.doe@example.com',
        'phone' => '9876543210',
    ]);

    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'John Doe Delivery',
        'phone' => '9876543210',
        'address_line' => '123 Toy Lane',
        'locality' => 'Indiranagar',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'pincode' => '560038',
        'type' => 'home',
        'is_default' => true,
    ]);

    $brand = Brand::create(['name' => 'InsightBrand', 'slug' => 'insightbrand-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'InsightToys', 'slug' => 'insighttoys-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Robotics', 'slug' => 'robotics-'.uniqid(), 'hsn_code' => '9503', 'gst_rate' => 18.0]);

    // Seller A
    $sellerUserA = User::factory()->create(['name' => 'Seller Alice Owner', 'email' => 'alice@seller.com']);
    $sellerA = SellerProfile::factory()->create([
        'user_id' => $sellerUserA->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Alpha Galaxy Store',
        'gstin' => '27AAAAA1111A1Z1',
        'pickup_state' => 'Maharashtra',
    ]);

    // Seller B
    $sellerUserB = User::factory()->create(['name' => 'Seller Bob Owner', 'email' => 'bob@seller.com']);
    $sellerB = SellerProfile::factory()->create([
        'user_id' => $sellerUserB->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Beta Nebula Shop',
        'gstin' => '29BBBBB2222B1Z2',
        'pickup_state' => 'Karnataka',
    ]);

    // Products & Variants
    $prod1 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Alpha Super Mech',
        'slug' => 'alpha-super-mech-'.uniqid(),
        'description' => 'A large mech robot',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    ProductImage::create(['product_id' => $prod1->id, 'path' => 'products/p1.jpg', 'is_primary' => true, 'status' => ProductImageStatus::Live, 'sort_order' => 1]);
    $var1 = ProductVariant::create([
        'product_id' => $prod1->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'Red'],
        'attribute_snapshot' => ['color' => ['value' => 'Red', 'label' => 'Red Color']],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    $listingA = Listing::create([
        'seller_profile_id' => $sellerA->id,
        'product_variant_id' => $var1->id,
        'sku' => 'SKU-ALPHAMECH-RED',
        'mrp' => 150000,
        'selling_price' => 100000,
        'stock' => 20,
        'status' => ListingStatus::Active,
    ]);

    $prod2 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Beta Stellar Drone',
        'slug' => 'beta-stellar-drone-'.uniqid(),
        'description' => 'A flying drone',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    ProductImage::create(['product_id' => $prod2->id, 'path' => 'products/p2.jpg', 'is_primary' => true, 'status' => ProductImageStatus::Live, 'sort_order' => 1]);
    $var2 = ProductVariant::create([
        'product_id' => $prod2->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'Blue'],
        'attribute_snapshot' => ['color' => ['value' => 'Blue', 'label' => 'Blue Color']],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    $listingB = Listing::create([
        'seller_profile_id' => $sellerB->id,
        'product_variant_id' => $var2->id,
        'sku' => 'SKU-BETADRONE-BLU',
        'mrp' => 80000,
        'selling_price' => 50000,
        'stock' => 20,
        'status' => ListingStatus::Active,
    ]);

    return compact(
        'customer',
        'address',
        'sellerA',
        'sellerB',
        'sellerUserA',
        'sellerUserB',
        'listingA',
        'listingB',
        'var1',
        'var2',
        'prod1',
        'prod2'
    );
}

test('admin orders list displays filtered orders, handles exact/prefix and contains search, and summary bar metrics', function () {
    $ctx = setupAdminOrderInsightContext();
    $admin = createAdminWithPermissions([
        AdminPermissions::ORDERS_VIEW,
        AdminPermissions::CUSTOMERS_VIEW,
        AdminPermissions::SELLERS_VIEW,
    ]);

    // Place an order with 2 packages: Seller A (₹1000) and Seller B (₹500)
    $service = app(OrderService::class);
    $order = $service->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listingA']->id, 'qty' => 1],
            ['listing_id' => $ctx['listingB']->id, 'qty' => 1],
        ],
        expectedTotal: 150000,
        idempotencyKey: (string) Str::ulid()
    ));

    // Cancel Seller B's package
    $sellerOrderB = $order->sellerOrders()->where('seller_profile_id', $ctx['sellerB']->id)->firstOrFail();
    $service->cancelSellerOrder($sellerOrderB, 'admin', $admin->id, 'Buyer request');

    // Verify order status is partially_cancelled
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::PartiallyCancelled);

    $test = Livewire::actingAs($admin, 'admin')->test('pages::admin.orders-index');

    // Summary bar: gross value = 150000 (₹1500), cancelled = 50000 (₹500), net = 100000 (₹1000), items sold = 1
    $test->assertSeeHtml('data-test="summary-orders-count"')
        ->assertSee('1')
        ->assertSeeHtml('data-test="summary-net-value"')
        ->assertSee('1,000')
        ->assertSeeHtml('data-test="summary-items-sold"')
        ->assertSee('1');

    // Search by prefix of order number
    $prefix = substr($order->order_number, 0, 8);
    $test->set('search', $prefix)
        ->assertSee($order->order_number);

    // Search by SKU (prefix/exact)
    $test->set('search', 'SKU-ALPHAMECH')
        ->assertSee($order->order_number);

    // Search by customer name (contains)
    $test->set('search', 'Customer Doe')
        ->assertSee($order->order_number);

    // Search by seller store name (contains)
    $test->set('search', 'Nebula Shop')
        ->assertSee($order->order_number);

    // Filter by sellerOrderStatus
    $test->set('search', '')
        ->set('sellerOrderStatus', 'cancelled')
        ->assertSee($order->order_number)
        ->set('sellerOrderStatus', 'delivered')
        ->assertDontSee($order->order_number);

    // Filter by amount range
    $test->set('sellerOrderStatus', 'all')
        ->set('amountMin', 1000)
        ->set('amountMax', 2000)
        ->assertSee($order->order_number)
        ->set('amountMin', 3000)
        ->assertDontSee($order->order_number);
});

test('searching by customer email and phone requires customers.view permission', function () {
    $ctx = setupAdminOrderInsightContext();

    $service = app(OrderService::class);
    $order = $service->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listingA']->id, 'qty' => 1],
        ],
        expectedTotal: 100000,
        idempotencyKey: (string) Str::ulid()
    ));

    // 1. Admin WITH customers.view
    $adminWithCust = createAdminWithPermissions([
        AdminPermissions::ORDERS_VIEW,
        AdminPermissions::CUSTOMERS_VIEW,
    ]);

    Livewire::actingAs($adminWithCust, 'admin')
        ->test('pages::admin.orders-index')
        ->set('search', 'john.doe@example.com')
        ->assertSee($order->order_number)
        ->set('search', '9876543210')
        ->assertSee($order->order_number);

    // 2. Admin WITHOUT customers.view
    $adminWithoutCust = createAdminWithPermissions([
        AdminPermissions::ORDERS_VIEW,
    ]);

    Livewire::actingAs($adminWithoutCust, 'admin')
        ->test('pages::admin.orders-index')
        ->set('search', 'john.doe@example.com')
        ->assertDontSee($order->order_number)
        ->set('search', '9876543210')
        ->assertDontSee($order->order_number)
        // But prefix search by order number still works!
        ->set('search', substr($order->order_number, 0, 8))
        ->assertSee($order->order_number);
});

test('orders list eager loading has zero N+1 queries', function () {
    $ctx = setupAdminOrderInsightContext();
    $admin = createAdminWithPermissions([
        AdminPermissions::ORDERS_VIEW,
        AdminPermissions::CUSTOMERS_VIEW,
        AdminPermissions::SELLERS_VIEW,
    ]);

    $service = app(OrderService::class);

    // Create 1 order first
    $service->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [['listing_id' => $ctx['listingA']->id, 'qty' => 1]],
        expectedTotal: 100000,
        idempotencyKey: (string) Str::ulid()
    ));

    // Warm up permission cache & livewire internals
    Livewire::actingAs($admin, 'admin')->test('pages::admin.orders-index');

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($admin, 'admin')->test('pages::admin.orders-index');
    $queryCount1 = count(DB::getQueryLog());

    // Create 3 more orders
    for ($i = 0; $i < 3; $i++) {
        $service->place(new PlaceOrderData(
            userId: $ctx['customer']->id,
            addressId: $ctx['address']->id,
            paymentMethod: 'cash_on_delivery',
            lines: [['listing_id' => $ctx['listingA']->id, 'qty' => 1]],
            expectedTotal: 100000,
            idempotencyKey: (string) Str::ulid()
        ));
    }

    DB::flushQueryLog();
    Livewire::actingAs($admin, 'admin')->test('pages::admin.orders-index');
    $queryCount2 = count(DB::getQueryLog());

    DB::disableQueryLog();

    // Query count must remain identical (zero N+1 queries)
    expect($queryCount2)->toBe($queryCount1);
});

test('admin order details shows masked customer data when lacking customers.view, full data when authorized, and per-seller split sums exactly to grand total', function () {
    $ctx = setupAdminOrderInsightContext();

    $service = app(OrderService::class);
    $order = $service->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listingA']->id, 'qty' => 1],
            ['listing_id' => $ctx['listingB']->id, 'qty' => 1],
        ],
        expectedTotal: 150000,
        idempotencyKey: (string) Str::ulid()
    ));

    // Verify per-seller split amounts sum exactly to grand total
    $sellerOrderSum = $order->sellerOrders->sum('grand_total');
    expect($sellerOrderSum)->toBe($order->grand_total);

    // 1. Admin without customers.view & without sellers.view
    $restrictedAdmin = createAdminWithPermissions([
        AdminPermissions::ORDERS_VIEW,
    ]);

    $testRestricted = Livewire::actingAs($restrictedAdmin, 'admin')
        ->test('pages::admin.orders-show', ['order' => $order]);

    // Masked customer email & phone in customer card
    $testRestricted->assertSeeHtml('data-test="customer-email-display"')
        ->assertSee('j•••@example.com')
        ->assertSeeHtml('data-test="customer-phone-display"')
        ->assertSee('••••••3210')
        // Delivery address snapshot remains visible with full unmasked address and phone
        ->assertSeeHtml('data-test="delivery-address-card"')
        ->assertSee('John Doe Delivery')
        ->assertSee('Indiranagar')
        ->assertSee('9876543210')
        // No links to customer profile or seller profile
        ->assertDontSeeHtml('data-test="view-customer-profile-link"')
        ->assertDontSeeHtml('data-test="seller-store-link"')
        // Lifetime orders count is displayed
        ->assertSeeHtml('data-test="customer-lifetime-orders"')
        ->assertSee('1')
        // Per-seller split is rendered
        ->assertSeeHtml('data-test="per-seller-split-section"')
        ->assertSee('Alpha Galaxy Store')
        ->assertSee('Beta Nebula Shop');

    // 2. Admin with full permissions
    $fullAdmin = createAdminWithPermissions([
        AdminPermissions::ORDERS_VIEW,
        AdminPermissions::CUSTOMERS_VIEW,
        AdminPermissions::SELLERS_VIEW,
    ]);

    $testFull = Livewire::actingAs($fullAdmin, 'admin')
        ->test('pages::admin.orders-show', ['order' => $order]);

    $testFull->assertSee('john.doe@example.com')
        ->assertSeeHtml('data-test="view-customer-profile-link"')
        ->assertSeeHtml('data-test="seller-store-link"');
});

test('admin order details displays deleted user and deleted seller cleanly for anonymized accounts', function () {
    $ctx = setupAdminOrderInsightContext();

    $service = app(OrderService::class);
    $order = $service->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listingA']->id, 'qty' => 1],
        ],
        expectedTotal: 100000,
        idempotencyKey: (string) Str::ulid()
    ));

    // Deliver order so accounts can be anonymized/deleted
    $sellerOrder = $order->sellerOrders()->firstOrFail();
    $service->transitionSellerOrderStatus($sellerOrder, SellerOrderStatus::Confirmed, 'admin', 1);
    $service->transitionSellerOrderStatus($sellerOrder, SellerOrderStatus::Packed, 'admin', 1);
    $service->transitionSellerOrderStatus($sellerOrder, SellerOrderStatus::Shipped, 'admin', 1);
    $service->transitionSellerOrderStatus($sellerOrder, SellerOrderStatus::Delivered, 'admin', 1);

    // Anonymize customer
    $ctx['customer']->forceFill(['name' => 'Deleted user', 'email' => 'deleted+'.$ctx['customer']->id.'@invalid'])->save();

    // Anonymize seller
    $ctx['sellerA']->forceFill(['store_name' => 'Deleted seller'])->save();

    $admin = createAdminWithPermissions([
        AdminPermissions::ORDERS_VIEW,
        AdminPermissions::CUSTOMERS_VIEW,
        AdminPermissions::SELLERS_VIEW,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.orders-show', ['order' => $order->fresh()])
        ->assertSeeHtml('data-test="customer-anonymized"')
        ->assertSee('Deleted user')
        ->assertDontSeeHtml('data-test="view-customer-profile-link"')
        ->assertSeeHtml('data-test="seller-anonymized"')
        ->assertSee('Deleted seller')
        ->assertDontSeeHtml('data-test="seller-store-link"');
});

test('admin seller show orders tab is permission-gated and displays correct seller summary and top products', function () {
    $ctx = setupAdminOrderInsightContext();

    $service = app(OrderService::class);
    $order1 = $service->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listingA']->id, 'qty' => 2],
        ],
        expectedTotal: 200000,
        idempotencyKey: (string) Str::ulid()
    ));

    $order2 = $service->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listingA']->id, 'qty' => 1],
            ['listing_id' => $ctx['listingB']->id, 'qty' => 1],
        ],
        expectedTotal: 150000,
        idempotencyKey: (string) Str::ulid()
    ));

    // Cancel Seller A's package on order 2
    $soA2 = $order2->sellerOrders()->where('seller_profile_id', $ctx['sellerA']->id)->firstOrFail();
    $service->cancelSellerOrder($soA2, 'admin', 1, 'Stock damaged');

    // 1. Admin with sellers.view but WITHOUT orders.view
    $sellerAdminOnly = createAdminWithPermissions([
        AdminPermissions::SELLERS_VIEW,
    ]);

    // Overview tab works
    Livewire::actingAs($sellerAdminOnly, 'admin')
        ->test('pages::admin.sellers-show', ['sellerProfile' => $ctx['sellerA']])
        ->assertOk()
        ->assertDontSeeHtml('data-test="tab-orders"');

    // Setting tab=orders directly aborts with 403
    Livewire::actingAs($sellerAdminOnly, 'admin')
        ->withQueryParams(['tab' => 'orders'])
        ->test('pages::admin.sellers-show', ['sellerProfile' => $ctx['sellerA']])
        ->assertForbidden();

    // 2. Admin with both sellers.view and orders.view
    $authorizedAdmin = createAdminWithPermissions([
        AdminPermissions::SELLERS_VIEW,
        AdminPermissions::ORDERS_VIEW,
    ]);

    $test = Livewire::actingAs($authorizedAdmin, 'admin')
        ->withQueryParams(['tab' => 'orders'])
        ->test('pages::admin.sellers-show', ['sellerProfile' => $ctx['sellerA']])
        ->assertOk()
        ->assertSeeHtml('data-test="seller-orders-tab-content"');

    // Seller A has 2 orders: order1 (₹2000) active, order2 (₹1000) cancelled
    // Total orders = 2, Gross = 300000 (₹3000), Cancelled = 100000 (₹1000), Net = 200000 (₹2000), Items sold = 2
    $test->assertSeeHtml('data-test="seller-summary-orders-count"')
        ->assertSee('2')
        ->assertSeeHtml('data-test="seller-summary-net-value"')
        ->assertSee('2,000')
        ->assertSeeHtml('data-test="seller-summary-gross-value"')
        ->assertSee('3,000')
        ->assertSeeHtml('data-test="seller-summary-cancelled-value"')
        ->assertSee('1,000')
        ->assertSeeHtml('data-test="seller-summary-items-sold"')
        ->assertSee('2');

    // Top 5 products by UPID
    $test->assertSeeHtml('data-test="top-product-title"')
        ->assertSee('Alpha Super Mech')
        ->assertSeeHtml('data-test="top-product-units"')
        ->assertSee('2');
});

test('admin customer details orders tab is permission-gated and displays customer spending analytics including partial cancellation AOV', function () {
    $ctx = setupAdminOrderInsightContext();

    $service = app(OrderService::class);

    // Order 1: 2 packages -> Seller A (₹1000) and Seller B (₹500, cancelled)
    // Gross = 1500, Net = 1000. It has 1 active package, so counts towards AOV!
    $order1 = $service->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listingA']->id, 'qty' => 1],
            ['listing_id' => $ctx['listingB']->id, 'qty' => 1],
        ],
        expectedTotal: 150000,
        idempotencyKey: (string) Str::ulid()
    ));

    $soB1 = $order1->sellerOrders()->where('seller_profile_id', $ctx['sellerB']->id)->firstOrFail();
    $service->cancelSellerOrder($soB1, 'admin', 1, 'Customer changed mind');

    // Order 2: 1 package -> Seller A (₹2000) fully active
    $order2 = $service->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listingA']->id, 'qty' => 2],
        ],
        expectedTotal: 200000,
        idempotencyKey: (string) Str::ulid()
    ));

    // Total orders = 2
    // Gross value = 150000 + 200000 = 350000 (₹3500)
    // Cancelled value = 50000 (₹500)
    // Net value (total spent) = 300000 (₹3000)
    // Orders with >= 1 non-cancelled package = 2 (Order 1 has Seller A, Order 2 has Seller A)
    // AOV = 300000 / 2 = 150000 (₹1500)

    // 1. Admin with customers.view but WITHOUT orders.view
    $customerAdminOnly = createAdminWithPermissions([
        AdminPermissions::CUSTOMERS_VIEW,
    ]);

    Livewire::actingAs($customerAdminOnly, 'admin')
        ->test('pages::admin.customer-details', ['user' => $ctx['customer']])
        ->assertOk()
        ->assertDontSeeHtml('data-test="tab-orders"');

    // Accessing tab=orders directly aborts with 403
    Livewire::actingAs($customerAdminOnly, 'admin')
        ->withQueryParams(['tab' => 'orders'])
        ->test('pages::admin.customer-details', ['user' => $ctx['customer']])
        ->assertForbidden();

    // 2. Admin with both customers.view and orders.view
    $authorizedAdmin = createAdminWithPermissions([
        AdminPermissions::CUSTOMERS_VIEW,
        AdminPermissions::ORDERS_VIEW,
    ]);

    $test = Livewire::actingAs($authorizedAdmin, 'admin')
        ->withQueryParams(['tab' => 'orders'])
        ->test('pages::admin.customer-details', ['user' => $ctx['customer']])
        ->assertOk()
        ->assertSeeHtml('data-test="customer-orders-tab-content"');

    $test->assertSeeHtml('data-test="customer-summary-orders-count"')
        ->assertSee('2')
        ->assertSeeHtml('data-test="customer-summary-total-spent"')
        ->assertSee('3,000')
        ->assertSeeHtml('data-test="customer-summary-gross-value"')
        ->assertSee('3,500')
        ->assertSeeHtml('data-test="customer-summary-cancelled-value"')
        ->assertSee('500')
        ->assertSeeHtml('data-test="customer-summary-aov"')
        ->assertSee('1,500');

    // Both orders appear in paginated table
    $test->assertSee($order1->order_number)
        ->assertSee($order2->order_number);
});

test('admin layout menu displays orders link when admin has orders.view and hides it when not', function () {
    $authorizedAdmin = createAdminWithPermissions([
        AdminPermissions::ORDERS_VIEW,
    ]);

    $responseWith = $this->actingAs($authorizedAdmin, 'admin')->get(route('admin.dashboard'));
    $responseWith->assertOk()
        ->assertSee(route('admin.orders.index'))
        ->assertSee('<span>Orders</span>', false);

    $unauthorizedAdmin = createAdminWithPermissions([
        AdminPermissions::CUSTOMERS_VIEW,
    ]);

    $responseWithout = $this->actingAs($unauthorizedAdmin, 'admin')->get(route('admin.dashboard'));
    $responseWithout->assertOk()
        ->assertDontSee(route('admin.orders.index'))
        ->assertDontSee('<span>Orders</span>', false);
});
