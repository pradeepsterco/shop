<?php

use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Models\VerticalAttribute;
use App\Support\AdminPermissions;
use Livewire\Livewire;
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
