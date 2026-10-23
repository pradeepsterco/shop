<?php

use App\Models\Admin;
use App\Models\Category;
use App\Models\SellerDocument;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Ensure permissions exist for the admin guard
    Artisan::call('permissions:sync');
});

function getRouteParam(string $routeName): array
{
    return match ($routeName) {
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
    ['admin.staff', AdminPermissions::ADMINS_VIEW],
    ['admin.roles', AdminPermissions::ROLES_MANAGE],
    ['admin.activity-logs', AdminPermissions::AUDIT_VIEW],
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
