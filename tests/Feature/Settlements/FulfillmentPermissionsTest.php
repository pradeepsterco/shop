<?php

use App\Models\Admin;
use App\Models\AdminActivityLog;
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
use App\Orders\Events\SellerOrderDelivered;
use App\Orders\Events\SellerOrderShipped;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\OrderService;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function createFulfillmentTestEnv(): array
{
    Artisan::call('permissions:sync');

    $category = Category::create(['name' => 'Toys', 'slug' => 'toys']);
    $vertical = Vertical::create([
        'category_id' => $category->id,
        'name' => 'Action Figures',
        'slug' => 'action-figures',
        'gst_rate' => 12.0,
        'hsn_code' => '950300',
    ]);
    $brand = Brand::create(['name' => 'Demo Brand', 'slug' => 'demo-brand', 'status' => 'approved']);

    $sellerUser = User::factory()->create(['email' => 'seller-fulfill@example.com']);
    $seller = SellerProfile::factory()->approved()->create([
        'user_id' => $sellerUser->id,
        'store_name' => 'Fulfillment Store',
        'pickup_state' => 'Maharashtra',
        'pickup_postal_code' => '400001',
    ]);

    $customer = User::factory()->create(['email' => 'customer-fulfill@example.com']);
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
        'upid' => 'VAR-FULFILL-001',
        'sku' => 'SKU-FULFILL-001',
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

    return compact('sellerUser', 'seller', 'customer', 'order', 'sellerOrder');
}

test('seller can mark order as shipped with required courier and tracking details', function () {
    Event::fake([SellerOrderShipped::class]);

    $env = createFulfillmentTestEnv();
    $sellerOrder = $env['sellerOrder'];

    // Move to Packed status so canShip() is true
    $sellerOrder->update(['status' => SellerOrderStatus::Packed]);

    // 1. Validation check: fails without courierName or trackingNumber
    Livewire::actingAs($env['sellerUser'], 'web')
        ->test('pages::seller.orders-show', ['sellerOrder' => $sellerOrder->id])
        ->set('courierName', '')
        ->set('trackingNumber', '')
        ->call('confirmShip')
        ->assertHasErrors(['courierName', 'trackingNumber']);

    // 2. Mark shipped with valid courier & tracking
    Livewire::actingAs($env['sellerUser'], 'web')
        ->test('pages::seller.orders-show', ['sellerOrder' => $sellerOrder->id])
        ->set('courierName', 'Delhivery')
        ->set('trackingNumber', 'DEL987654321')
        ->call('confirmShip')
        ->assertHasNoErrors();

    $sellerOrder->refresh();
    expect($sellerOrder->status)->toBe(SellerOrderStatus::Shipped)
        ->and($sellerOrder->courier_name)->toBe('Delhivery')
        ->and($sellerOrder->tracking_number)->toBe('DEL987654321');

    Event::assertDispatched(SellerOrderShipped::class);
});

test('admin with orders.manage can mark order as delivered and logs to activity log', function () {
    Event::fake([SellerOrderDelivered::class]);

    $env = createFulfillmentTestEnv();
    $order = $env['order'];
    $sellerOrder = $env['sellerOrder'];

    // Move to Shipped so canDeliver() is true
    $sellerOrder->update([
        'status' => SellerOrderStatus::Shipped,
        'courier_name' => 'Blue Dart',
        'tracking_number' => 'BD12345678',
    ]);

    $admin = Admin::factory()->create();
    $role = Role::findOrCreate('Order Manager', 'admin');
    $role->syncPermissions([AdminPermissions::ORDERS_VIEW, AdminPermissions::ORDERS_MANAGE]);
    $admin->assignRole($role);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.orders-show', ['order' => $order])
        ->call('markDelivered', $sellerOrder->id)
        ->assertHasNoErrors();

    $sellerOrder->refresh();
    expect($sellerOrder->status)->toBe(SellerOrderStatus::Delivered)
        ->and($sellerOrder->delivered_at)->not->toBeNull();

    // Verify AdminActivityLog
    $log = AdminActivityLog::where('action', 'orders.deliver')
        ->where('subject_id', $sellerOrder->id)
        ->first();
    expect($log)->not->toBeNull()
        ->and($log->admin_id)->toBe($admin->id);

    Event::assertDispatched(SellerOrderDelivered::class);
});

test('admin without orders.manage cannot mark order as delivered', function () {
    $env = createFulfillmentTestEnv();
    $order = $env['order'];
    $sellerOrder = $env['sellerOrder'];

    $sellerOrder->update([
        'status' => SellerOrderStatus::Shipped,
        'courier_name' => 'Blue Dart',
        'tracking_number' => 'BD12345678',
    ]);

    $viewOnlyAdmin = Admin::factory()->create();
    $role = Role::findOrCreate('Order Viewer Only', 'admin');
    $role->syncPermissions([AdminPermissions::ORDERS_VIEW]);
    $viewOnlyAdmin->assignRole($role);

    Livewire::actingAs($viewOnlyAdmin, 'admin')
        ->test('pages::admin.orders-show', ['order' => $order])
        ->call('markDelivered', $sellerOrder->id)
        ->assertForbidden();
});
