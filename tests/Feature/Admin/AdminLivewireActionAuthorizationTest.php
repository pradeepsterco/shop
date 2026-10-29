<?php

use App\Fees\Models\FeeSchedule;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Models\VerticalAttribute;
use App\Shared\Enums\FeeScheduleStatus;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use App\Support\AdminPermissions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Artisan::call('permissions:sync');
});

test('unauthorized staff cannot invoke customer management actions', function () {
    $staff = Admin::factory()->create();
    $role = Role::findOrCreate('Customer Viewer', 'admin');
    $role->syncPermissions([AdminPermissions::CUSTOMERS_VIEW]);
    $staff->assignRole($role);

    $customer = User::factory()->create();

    // pages::admin.customers -> openCreateCustomerModal & saveCustomer (requires customers.create)
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.customers')
        ->call('openCreateCustomerModal')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.customers')
        ->call('saveCustomer')
        ->assertForbidden();

    // pages::admin.customers -> openResetPasswordModal & confirmResetPassword (requires customers.password)
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.customers')
        ->call('openResetPasswordModal', $customer->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.customers')
        ->call('confirmResetPassword')
        ->assertForbidden();

    // pages::admin.customer-details -> openResetPasswordModal & confirmResetPassword (requires customers.password)
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.customer-details', ['user' => $customer])
        ->call('openResetPasswordModal')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.customer-details', ['user' => $customer])
        ->call('confirmResetPassword')
        ->assertForbidden();

    // pages::admin.customers -> toggleActive (requires customers.manage)
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.customers')
        ->call('toggleActive', $customer->id)
        ->assertForbidden();

    // pages::admin.customer-details -> toggleActive (requires customers.manage)
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.customer-details', ['user' => $customer])
        ->call('toggleActive')
        ->assertForbidden();
});

test('unauthorized staff cannot invoke seller management actions', function () {
    $staff = Admin::factory()->create();
    $seller = SellerProfile::factory()->create();

    // approveApplication
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.sellers-show', ['sellerProfile' => $seller])
        ->call('approveApplication')
        ->assertForbidden();

    // rejectApplication
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.sellers-show', ['sellerProfile' => $seller])
        ->set('rejection_reason', 'Not compliant with terms')
        ->call('rejectApplication')
        ->assertForbidden();

    // suspendSeller
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.sellers-show', ['sellerProfile' => $seller])
        ->call('suspendSeller')
        ->assertForbidden();

    // reactivateSeller
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.sellers-show', ['sellerProfile' => $seller])
        ->call('reactivateSeller')
        ->assertForbidden();
});

test('unauthorized staff cannot invoke category management actions', function () {
    $staff = Admin::factory()->create();
    $category = Category::create(['name' => 'Fashion']);

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.categories')
        ->call('openCreateModal', null)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.categories')
        ->call('openEditModal', $category->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.categories')
        ->set('name', 'Kids')
        ->call('saveCategory')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.categories')
        ->call('toggleActive', $category->id)
        ->assertForbidden();
});

test('unauthorized staff cannot invoke vertical management actions', function () {
    $staff = Admin::factory()->create();
    $category = Category::create(['name' => 'Toys']);
    $vertical = Vertical::create([
        'category_id' => $category->id,
        'name' => 'Puzzles',
        'hsn_code' => '9503',
        'gst_rate' => 12.0,
    ]);

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.verticals')
        ->call('openCreateModal')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.verticals')
        ->call('openEditModal', $vertical->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.verticals')
        ->set('name', 'Board Games')
        ->set('category_id', $category->id)
        ->set('hsn_code', '9504')
        ->set('gst_rate', '12.0')
        ->call('saveVertical')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.verticals')
        ->call('toggleActive', $vertical->id)
        ->assertForbidden();
});

test('unauthorized staff cannot invoke vertical attribute actions', function () {
    $staff = Admin::factory()->create();
    $category = Category::create(['name' => 'Footwear']);
    $vertical = Vertical::create([
        'category_id' => $category->id,
        'name' => 'Boots',
        'hsn_code' => '6403',
        'gst_rate' => 18.0,
    ]);
    $attr = VerticalAttribute::create([
        'vertical_id' => $vertical->id,
        'name' => 'Material',
        'code' => 'material',
        'type' => 'text',
    ]);

    $actions = [
        ['openCreateModal'],
        ['openEditModal', [$attr->id]],
        ['saveAttribute'],
        ['openOptionsModal', [$attr->id]],
        ['addOption'],
        ['toggleOptionActive', [0]],
        ['toggleOptionRetired', [0]],
        ['removeOption', [0]],
        ['deleteAttribute', [$attr->id]],
    ];

    foreach ($actions as $item) {
        $action = $item[0];
        $args = $item[1] ?? [];

        Livewire::actingAs($staff, 'admin')
            ->test('pages::admin.catalog.vertical-attributes', ['vertical' => $vertical])
            ->call($action, ...$args)
            ->assertForbidden();
    }
});

test('unauthorized staff cannot invoke brand actions', function () {
    $staff = Admin::factory()->create();
    $brand = Brand::create(['name' => 'Sample Brand']);

    // Manage brand actions
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.brands')
        ->call('openCreateModal')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.brands')
        ->set('name', 'New Brand')
        ->call('createBrand')
        ->assertForbidden();

    // Review brand actions
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.brands')
        ->call('approveBrand', $brand->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.brands')
        ->call('openRejectModal', $brand->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.brands')
        ->call('confirmReject')
        ->assertForbidden();
});

test('unauthorized staff cannot invoke product QC review actions', function () {
    $staff = Admin::factory()->create();
    $cat = Category::create(['name' => 'Electronics']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Drones', 'hsn_code' => '8806', 'gst_rate' => 18.0]);
    $brand = Brand::create(['name' => 'FlyHigh']);
    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'FPV Drone',
        'description' => 'Fast drone',
    ]);

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.products')
        ->call('openReviewModal', $product->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.products')
        ->call('openRejectModal', $product->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.products')
        ->call('approveProduct', $product->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.catalog.products')
        ->call('confirmReject')
        ->assertForbidden();
});

test('unauthorized staff cannot invoke roles management actions', function () {
    $staff = Admin::factory()->create();
    $role = Role::findOrCreate('Test Role', 'admin');

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.roles')
        ->assertForbidden();
});

test('view-only staff cannot invoke staff mutation actions', function () {
    // Staff with admins.view can view the staff list, but cannot mutate
    $staff = Admin::factory()->create();
    $role = Role::findOrCreate('Staff Viewer', 'admin');
    $role->syncPermissions([AdminPermissions::ADMINS_VIEW]);
    $staff->assignRole($role);

    $target = Admin::factory()->create();

    // openInviteModal -> forbidden without admins.manage
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.staff')
        ->call('openInviteModal')
        ->assertForbidden();

    // openEditModal -> forbidden without admins.manage
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.staff')
        ->call('openEditModal', $target->id)
        ->assertForbidden();

    // saveStaff -> forbidden without admins.manage
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.staff')
        ->set('name', 'Hacker')
        ->set('email', 'hacker@example.com')
        ->call('saveStaff')
        ->assertForbidden();

    // resendInvite -> forbidden without admins.manage
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.staff')
        ->call('resendInvite', $target->id)
        ->assertForbidden();

    // toggleActive -> forbidden without admins.manage
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.staff')
        ->call('toggleActive', $target->id)
        ->assertForbidden();

    // openResetPasswordModal -> forbidden without admins.password
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.staff')
        ->call('openResetPasswordModal', $target->id)
        ->assertForbidden();

    // confirmResetPassword -> forbidden without admins.password
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.staff')
        ->set('resetAdminId', $target->id)
        ->call('confirmResetPassword')
        ->assertForbidden();
});

test('view-only staff cannot invoke fee schedule mutation actions', function () {
    $staff = Admin::factory()->create();
    $role = Role::findOrCreate('Fee Viewer', 'admin');
    $role->syncPermissions([AdminPermissions::FEES_VIEW]);
    $staff->assignRole($role);

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

    $draft = FeeSchedule::create([
        'name' => 'Draft Sched',
        'version' => 2,
        'status' => FeeScheduleStatus::Draft,
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);

    // pages::admin.fees-index actions require fees.manage
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.fees-index')
        ->call('cloneSchedule', $schedule->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.fees-index')
        ->call('confirmActivate', $draft->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.fees-index')
        ->call('activate')
        ->assertForbidden();

    // pages::admin.fees-show actions require fees.manage
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.fees-show', ['feeSchedule' => $schedule])
        ->call('cloneSchedule')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.fees-show', ['feeSchedule' => $schedule])
        ->call('activateSchedule')
        ->assertForbidden();

    // pages::admin.fees-edit requires fees.manage to mount or save
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.fees-edit', ['feeSchedule' => $draft])
        ->assertForbidden();
});

test('view-only staff cannot invoke settlements and payout management actions', function () {
    $staff = Admin::factory()->create();
    $role = Role::findOrCreate('Finance Viewer', 'admin');
    $role->syncPermissions([AdminPermissions::SETTLEMENTS_VIEW, AdminPermissions::PAYOUTS_VIEW]);
    $staff->assignRole($role);

    // pages::admin.settlements-index -> releaseMatured requires payouts.manage
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.settlements-index')
        ->call('releaseMatured')
        ->assertForbidden();

    // pages::admin.payouts-index -> openCreateModal, createBatch, confirmMarkPaid, confirmMarkFailed, cancelBatch require payouts.manage
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.payouts-index')
        ->call('openCreateModal')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.payouts-index')
        ->call('createBatch')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.payouts-index')
        ->call('confirmMarkPaid')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.payouts-index')
        ->call('confirmMarkFailed')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.payouts-index')
        ->call('cancelBatch', 'test-batch-id')
        ->assertForbidden();
});

test('view-only staff cannot invoke shipping management actions', function () {
    $staff = Admin::factory()->create();
    $role = Role::findOrCreate('Shipping Viewer', 'admin');
    $role->syncPermissions([AdminPermissions::SHIPPING_VIEW]);
    $staff->assignRole($role);

    $shipment = Shipment::create([
        'seller_order_id' => (string) Str::ulid(),
        'order_id' => (string) Str::ulid(),
        'seller_profile_id' => 1,
        'provider' => 'fake',
        'awb' => 'FAKE-999',
        'status' => ShipmentStatus::Created,
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 15,
        'width_cm' => 10,
        'height_cm' => 5,
    ]);

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.shipping-settings')
        ->call('testConnection')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.shipping-settings')
        ->call('syncWarehouses')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call('pollTracking')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call('cancelShipment')
        ->assertForbidden();
});

test('view-only staff cannot invoke branding mutation actions', function () {
    $staff = Admin::factory()->create();
    $role = Role::findOrCreate('Branding Viewer', 'admin');
    $role->syncPermissions([AdminPermissions::BRANDING_VIEW]);
    $staff->assignRole($role);

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.settings.branding')
        ->assertOk()
        ->call('saveGeneral')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.settings.branding')
        ->call('saveLogo', 'storefront_logo_light')
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.settings.branding')
        ->call('resetLogo', 'storefront_logo_light')
        ->assertForbidden();
});

test('view-only shipping staff cannot invoke ndr or simulation actions', function () {
    config(['shipping.fake_simulation_enabled' => true]);

    $staff = Admin::factory()->create();
    $role = Role::findOrCreate('Shipping Viewer NDR', 'admin');
    $role->syncPermissions([AdminPermissions::SHIPPING_VIEW]);
    $staff->assignRole($role);

    $shipment = Shipment::create([
        'seller_order_id' => (string) Str::ulid(),
        'order_id' => (string) Str::ulid(),
        'seller_profile_id' => 1,
        'provider' => 'fake',
        'awb' => 'FAKE-NDR-RBAC',
        'status' => ShipmentStatus::DeliveryFailed,
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 15,
        'width_cm' => 10,
        'height_cm' => 5,
    ]);

    foreach ([['submitNdr', []], ['simulateTransition', ['rto_initiated']]] as [$method, $args]) {
        Livewire::actingAs($staff, 'admin')
            ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
            ->call($method, ...$args)
            ->assertForbidden();
    }

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::DeliveryFailed)
        ->and($shipment->fresh()->ndr_action)->toBeNull();
});

test('shipping managers without returns.manage cannot invoke returns actions', function (string $method, array $args, ShipmentStatus $status) {
    $staff = Admin::factory()->create();
    $role = Role::findOrCreate('Shipping Manager No Returns', 'admin');
    $role->syncPermissions([AdminPermissions::SHIPPING_VIEW, AdminPermissions::SHIPPING_MANAGE, AdminPermissions::RETURNS_VIEW]);
    $staff->assignRole($role);

    $shipment = Shipment::create([
        'seller_order_id' => (string) Str::ulid(),
        'order_id' => (string) Str::ulid(),
        'seller_profile_id' => 1,
        'provider' => 'fake',
        'awb' => 'FAKE-RET-'.Str::upper(Str::random(5)),
        'status' => $status,
        'declared_value_paise' => 10000,
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 15,
        'width_cm' => 10,
        'height_cm' => 5,
    ]);

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->call($method, ...$args)
        ->assertForbidden();
})->with([
    'confirm lost' => ['confirmLostShipment', [], ShipmentStatus::Lost],
    'confirm partial delivery' => ['submitPartialDelivery', [], ShipmentStatus::PartialDelivered],
    'rto restock' => ['submitRtoDecision', ['restocked'], ShipmentStatus::RtoDelivered],
    'rto write-off' => ['submitRtoDecision', ['written_off'], ShipmentStatus::RtoDelivered],
    'claim status' => ['updateClaimStatus', ['claim-id', 'approved'], ShipmentStatus::Lost],
]);

test('staff without returns.view do not see rto reports or courier claims on the shipment page', function () {
    $staff = Admin::factory()->create();
    $role = Role::findOrCreate('Shipping Viewer No Returns', 'admin');
    $role->syncPermissions([AdminPermissions::SHIPPING_VIEW]);
    $staff->assignRole($role);

    $shipment = Shipment::create([
        'seller_order_id' => (string) Str::ulid(),
        'order_id' => (string) Str::ulid(),
        'seller_profile_id' => 1,
        'provider' => 'fake',
        'awb' => 'FAKE-RET-HIDDEN',
        'status' => ShipmentStatus::RtoDelivered,
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 15,
        'width_cm' => 10,
        'height_cm' => 5,
    ]);

    $this->actingAs($staff, 'admin')
        ->get(route('admin.shipments.show', ['shipment' => $shipment->id]))
        ->assertOk()
        ->assertDontSee('data-test="rto-review-card"', false);

    $role->givePermissionTo(AdminPermissions::RETURNS_VIEW);

    $this->actingAs($staff->fresh(), 'admin')
        ->get(route('admin.shipments.show', ['shipment' => $shipment->id]))
        ->assertOk()
        ->assertSee('data-test="rto-review-card"', false)
        ->assertDontSee('data-test="rto-writeoff-btn"', false);
});

test('returns permissions are registered and synced to the admin guard', function () {
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(AdminPermissions::all())->toContain(AdminPermissions::RETURNS_VIEW, AdminPermissions::RETURNS_MANAGE, AdminPermissions::REFUNDS_MANAGE)
        ->and(Permission::where('guard_name', 'admin')->whereIn('name', [AdminPermissions::RETURNS_VIEW, AdminPermissions::RETURNS_MANAGE, AdminPermissions::REFUNDS_MANAGE])->count())->toBe(3)
        ->and(Role::findByName('Super Admin', 'admin')->hasPermissionTo(AdminPermissions::REFUNDS_MANAGE))->toBeTrue()
        ->and(Role::findByName('Super Admin', 'admin')->hasPermissionTo(AdminPermissions::RETURNS_MANAGE))->toBeTrue();
});

test('staff without refunds.manage cannot record refund payouts or failures', function (string $method) {
    $staff = Admin::factory()->create();
    $role = Role::findOrCreate('Returns Manager No Refunds', 'admin');
    $role->syncPermissions([AdminPermissions::RETURNS_VIEW, AdminPermissions::RETURNS_MANAGE]);
    $staff->assignRole($role);

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.refunds-index')
        ->call($method, (string) Str::ulid())
        ->assertForbidden();
})->with(['markRefundPaid', 'markRefundFailed']);

test('view-only shipping staff cannot retry a courier booking', function () {
    $staff = Admin::factory()->create();
    $staff->assignRole(Role::findOrCreate('Shipping Viewer Booking', 'admin')->syncPermissions([AdminPermissions::SHIPPING_VIEW]));

    $shipment = Shipment::create([
        'seller_order_id' => (string) Str::ulid(), 'order_id' => (string) Str::ulid(), 'seller_profile_id' => 1,
        'provider' => 'fake', 'status' => ShipmentStatus::Created, 'booking_status' => Shipment::BOOKING_FAILED,
        'actual_weight_grams' => 500, 'volumetric_weight_grams' => 500, 'chargeable_weight_grams' => 500,
        'length_cm' => 15, 'width_cm' => 10, 'height_cm' => 5,
    ]);

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->assertDontSee('data-test="retry-booking-btn"', false)
        ->call('retryBooking')
        ->assertForbidden();

    expect($shipment->fresh()->booking_status)->toBe(Shipment::BOOKING_FAILED);
});
