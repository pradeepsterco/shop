<?php

use App\Models\Admin;
use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Artisan::call('permissions:sync');
});

test('shipping settings and shipments index require shipping.view permission', function () {
    $staff = Admin::factory()->create();

    // Guest / unauthorized
    $this->actingAs($staff, 'admin')
        ->get(route('admin.shipping.settings'))
        ->assertForbidden();

    $this->actingAs($staff, 'admin')
        ->get(route('admin.shipments.index'))
        ->assertForbidden();

    // Authorized
    $role = Role::findOrCreate('Shipping Officer', 'admin');
    $role->givePermissionTo(AdminPermissions::SHIPPING_VIEW);
    $staff->assignRole($role);

    $this->actingAs($staff->fresh(), 'admin')
        ->get(route('admin.shipping.settings'))
        ->assertOk();

    $this->actingAs($staff->fresh(), 'admin')
        ->get(route('admin.shipments.index'))
        ->assertOk();
});

test('seller can only download their own labels and cannot download labels of other sellers', function () {
    Storage::fake('local');

    $sellerUser1 = User::factory()->create();
    $sellerProfile1 = SellerProfile::factory()->create(['user_id' => $sellerUser1->id]);

    $sellerUser2 = User::factory()->create();
    $sellerProfile2 = SellerProfile::factory()->create(['user_id' => $sellerUser2->id]);

    $labelPath = 'shipping_labels/shipment-123.pdf';
    Storage::disk('local')->put($labelPath, '%PDF-1.4 Mock Label Content');

    $shipment = Shipment::create([
        'seller_order_id' => 'so-1',
        'order_id' => 'ord-1',
        'seller_profile_id' => $sellerProfile1->id,
        'provider' => 'fake',
        'awb' => 'AWB-123456',
        'status' => ShipmentStatus::InTransit,
        'label_path' => $labelPath,
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    // Seller 1 (owner) can download the label
    $this->actingAs($sellerUser1)
        ->get(route('shipping.shipments.label', $shipment))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="shipping-label-AWB-123456.pdf"');

    // Seller 2 (different seller) cannot download Seller 1's label
    $this->actingAs($sellerUser2)
        ->get(route('shipping.shipments.label', $shipment))
        ->assertForbidden();

    // Unauthenticated user is redirected to login
    auth()->logout();
    $this->get(route('shipping.shipments.label', $shipment))
        ->assertRedirect(route('login'));
});

test('customer can only view tracking for their own order and cannot view tracking of another customer', function () {
    $customer1 = User::factory()->create();
    $customer2 = User::factory()->create();
    $seller = SellerProfile::factory()->create();

    $order1 = Order::create([
        'order_number' => 'KH-'.date('Ymd').'-'.strtoupper(Str::random(6)),
        'user_id' => $customer1->id,
        'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Placed,
        'delivery_address' => [
            'name' => 'Customer 1',
            'phone' => '9876543210',
            'address_line' => 'Address 1',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
        ],
        'delivery_state' => 'Karnataka',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending,
        'items_mrp_total' => 10000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 10000,
        'placed_at' => now(),
    ]);

    $sellerOrder1 = SellerOrder::create([
        'order_id' => $order1->id,
        'seller_profile_id' => $seller->id,
        'seller_store_name' => 'Store 1',
        'status' => SellerOrderStatus::Shipped,
        'subtotal' => 10000,
        'discount_total' => 0,
        'grand_total' => 10000,
        'placed_at' => now(),
    ]);

    Shipment::create([
        'seller_order_id' => $sellerOrder1->id,
        'order_id' => $order1->id,
        'seller_profile_id' => $seller->id,
        'provider' => 'ekart',
        'awb' => 'AWB-CUST-1',
        'status' => ShipmentStatus::InTransit,
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    // Customer 1 sees their order and tracking info
    $this->actingAs($customer1)
        ->get(route('orders.show', $order1->order_number))
        ->assertOk()
        ->assertSee('AWB-CUST-1')
        ->assertSee('In Transit');

    // Customer 2 cannot access Customer 1's order or tracking
    $this->actingAs($customer2)
        ->get(route('orders.show', $order1->order_number))
        ->assertForbidden();
});
