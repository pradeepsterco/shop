<?php

use App\Fees\Models\FeeSchedule;
use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Services\HelpdeskService;
use App\Models\Admin;
use App\Models\Category;
use App\Models\SellerDocument;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Models\Order;
use App\Returns\Enums\ReturnReason;
use App\Returns\Enums\ReturnRequestStatus;
use App\Returns\Enums\RtoConditionStatus;
use App\Returns\Models\ReturnRequest;
use App\Returns\Models\RtoIntakeReport;
use App\Shared\Enums\FeeScheduleStatus;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Ensure permissions exist for the admin guard
    Artisan::call('permissions:sync');
});

function getRouteParam(string $routeName): array
{
    return match ($routeName) {
        'admin.returns.request-photo' => [
            'request' => (function () {
                Storage::disk('local')->put('return_photos/rbac-test/photo.jpg', 'jpeg bytes');

                return ReturnRequest::create([
                    'order_id' => (string) Str::ulid(),
                    'seller_order_id' => (string) Str::ulid(),
                    'user_id' => User::factory()->create()->id,
                    'reason' => ReturnReason::Damaged,
                    'preference' => ReturnRequest::PREFERENCE_REFUND,
                    'status' => ReturnRequestStatus::Requested,
                    'photos' => ['return_photos/rbac-test/photo.jpg'],
                ]);
            })(),
            'kind' => 'customer',
            'index' => 0,
        ],
        'admin.returns.rto-photo' => [
            'report' => (function () {
                Storage::disk('local')->put('rto_photos/rbac-test/photo.jpg', 'jpeg bytes');

                return RtoIntakeReport::create([
                    'seller_order_id' => (string) Str::ulid(),
                    'shipment_id' => (string) Str::ulid(),
                    'seller_profile_id' => SellerProfile::factory()->create()->id,
                    'condition_status' => RtoConditionStatus::Damaged,
                    'photos' => ['rto_photos/rbac-test/photo.jpg'],
                    'reported_at' => now(),
                ]);
            })(),
            'index' => 0,
        ],
        'admin.support.show' => [
            'ticket' => app(HelpdeskService::class)->openCustomerTicket(
                user: User::factory()->create(),
                category: TicketCategory::Other,
                subject: 'RBAC check ticket',
                body: 'Checking the support route permissions.',
            )->ticket_number,
        ],
        'admin.customers.show' => [
            'user' => User::factory()->create(),
        ],
        'admin.sellers.show' => [
            'sellerProfile' => SellerProfile::factory()->create(),
        ],
        'admin.sellers.documents.show' => [
            'document' => (function () {
                Storage::disk('local')->put('kyc-documents/test.pdf', 'dummy content');
                $profile = SellerProfile::factory()->create();

                return SellerDocument::create([
                    'seller_profile_id' => $profile->id,
                    'type' => 'pan_card',
                    'path' => 'kyc-documents/test.pdf',
                    'original_name' => 'test.pdf',
                ]);
            })(),
        ],
        'admin.catalog.vertical-attributes' => [
            'vertical' => (function () {
                $category = Category::create(['name' => 'Toys']);

                return Vertical::create([
                    'category_id' => $category->id,
                    'name' => 'Action Figures',
                    'hsn_code' => '9503',
                    'gst_rate' => 18.0,
                ]);
            })(),
        ],
        'admin.orders.show' => [
            'order' => (function () {
                $user = User::factory()->create();

                return Order::create([
                    'order_number' => 'KH-20261024-TEST01',
                    'user_id' => $user->id,
                    'checkout_intent_id' => (string) Str::ulid(),
                    'status' => OrderStatus::Placed,
                    'delivery_address' => [
                        'name' => 'Test User',
                        'phone' => '9876543210',
                        'address_line' => '123 Test St',
                        'locality' => 'Test',
                        'city' => 'Bengaluru',
                        'state' => 'Karnataka',
                        'pincode' => '560001',
                    ],
                    'delivery_state' => 'Karnataka',
                    'payment_method' => PaymentMethod::CashOnDelivery,
                    'payment_status' => PaymentStatus::Pending,
                    'items_mrp_total' => 10000,
                    'discount_total' => 2000,
                    'delivery_fee' => 0,
                    'grand_total' => 8000,
                    'placed_at' => now(),
                ]);
            })(),
        ],
        'admin.fees.show' => [
            'feeSchedule' => (function () {
                return FeeSchedule::create([
                    'name' => 'Route Param Sched',
                    'version' => 1,
                    'status' => FeeScheduleStatus::Active,
                    'effective_from' => now()->subDay(),
                    'gst_rate' => 18.00,
                    'tcs_rate' => 0.50,
                    'tds_standard_rate' => 0.10,
                    'tds_no_pan_rate' => 5.00,
                    'default_weight_grams' => 500,
                ]);
            })(),
        ],
        'admin.fees.edit' => [
            'feeSchedule' => (function () {
                return FeeSchedule::create([
                    'name' => 'Draft Route Param Sched',
                    'version' => 1,
                    'status' => FeeScheduleStatus::Draft,
                    'gst_rate' => 18.00,
                    'tcs_rate' => 0.50,
                    'tds_standard_rate' => 0.10,
                    'tds_no_pan_rate' => 5.00,
                    'default_weight_grams' => 500,
                ]);
            })(),
        ],
        'admin.shipments.show' => [
            'shipment' => (function () {
                return Shipment::create([
                    'seller_order_id' => (string) Str::ulid(),
                    'order_id' => (string) Str::ulid(),
                    'seller_profile_id' => 1,
                    'provider' => 'fake',
                    'awb' => 'FAKE-1234567890',
                    'status' => ShipmentStatus::Created,
                    'actual_weight_grams' => 500,
                    'volumetric_weight_grams' => 500,
                    'chargeable_weight_grams' => 500,
                    'length_cm' => 15,
                    'width_cm' => 10,
                    'height_cm' => 5,
                ]);
            })(),
        ],
        default => [],
    };
}

dataset('admin_protected_routes', [
    ['admin.customers', AdminPermissions::CUSTOMERS_VIEW],
    ['admin.customers.show', AdminPermissions::CUSTOMERS_VIEW],
    ['admin.sellers.index', AdminPermissions::SELLERS_VIEW],
    ['admin.sellers.show', AdminPermissions::SELLERS_VIEW],
    ['admin.sellers.documents.show', AdminPermissions::SELLERS_KYC],
    ['admin.catalog.categories', AdminPermissions::CATALOG_CATEGORIES],
    ['admin.catalog.verticals', AdminPermissions::CATALOG_VERTICALS],
    ['admin.catalog.vertical-attributes', AdminPermissions::CATALOG_ATTRIBUTES],
    ['admin.catalog.brands', AdminPermissions::CATALOG_BRANDS_MANAGE],
    ['admin.catalog.products', AdminPermissions::CATALOG_PRODUCTS_VIEW],
    ['admin.orders.index', AdminPermissions::ORDERS_VIEW],
    ['admin.orders.show', AdminPermissions::ORDERS_VIEW],
    ['admin.fees.index', AdminPermissions::FEES_VIEW],
    ['admin.fees.show', AdminPermissions::FEES_VIEW],
    ['admin.fees.create', AdminPermissions::FEES_MANAGE],
    ['admin.fees.edit', AdminPermissions::FEES_MANAGE],
    ['admin.settlements.index', AdminPermissions::SETTLEMENTS_VIEW],
    ['admin.settlements.reports', AdminPermissions::SETTLEMENTS_VIEW],
    ['admin.payouts.index', AdminPermissions::PAYOUTS_VIEW],
    ['admin.staff', AdminPermissions::ADMINS_VIEW],
    ['admin.roles', AdminPermissions::ROLES_MANAGE],
    ['admin.activity-logs', AdminPermissions::AUDIT_VIEW],
    ['admin.shipping.settings', AdminPermissions::SHIPPING_VIEW],
    ['admin.shipments.index', AdminPermissions::SHIPPING_VIEW],
    ['admin.shipments.show', AdminPermissions::SHIPPING_VIEW],
    ['admin.returns.index', AdminPermissions::RETURNS_VIEW],
    ['admin.returns.rto-photo', AdminPermissions::RETURNS_VIEW],
    ['admin.refunds.index', AdminPermissions::RETURNS_VIEW],
    ['admin.returns.request-photo', AdminPermissions::RETURNS_VIEW],
    ['admin.settings.branding', AdminPermissions::BRANDING_VIEW],
    ['admin.support.index', AdminPermissions::SUPPORT_VIEW],
    ['admin.support.show', AdminPermissions::SUPPORT_VIEW],
    ['admin.support.settings', AdminPermissions::SUPPORT_SETTINGS],
    ['admin.support.report', AdminPermissions::SUPPORT_VIEW],
]);

test('admin route returns 403 when staff lacks required permission and 200 when granted', function (string $routeName, string $permission) {
    $params = getRouteParam($routeName);
    $url = route($routeName, $params);

    // 1. Staff without the permission -> 403
    $staff = Admin::factory()->create();
    $roleWithoutPerm = Role::findOrCreate('Test Role Without Perm', 'admin');
    $staff->assignRole($roleWithoutPerm);

    $response = $this->actingAs($staff, 'admin')->get($url);
    $response->assertForbidden();

    // 2. Staff with the permission -> 200
    $roleWithPerm = Role::findOrCreate('Test Role With Perm', 'admin');
    $roleWithPerm->givePermissionTo($permission);
    $staff->assignRole($roleWithPerm);

    $allowedResponse = $this->actingAs($staff->fresh(), 'admin')->get($url);
    $allowedResponse->assertOk();
})->with('admin_protected_routes');

test('web customer or seller cannot pass any admin permission check and is redirected to admin login', function (string $routeName) {
    $params = getRouteParam($routeName);
    $url = route($routeName, $params);

    $customer = User::factory()->create();

    // Even if a web user somehow had a permission assigned in web guard
    $response = $this->actingAs($customer, 'web')->get($url);
    $response->assertRedirect(route('admin.login'));
})->with('admin_protected_routes');

test('guest is redirected to admin login for all protected routes', function (string $routeName) {
    $params = getRouteParam($routeName);
    $url = route($routeName, $params);

    $this->get($url)->assertRedirect(route('admin.login'));
})->with('admin_protected_routes');
