<?php

use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Mail\StaffInvitationMail;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerDocument;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Artisan::call('permissions:sync');
});

// --- PRIVILEGE ESCALATION PREVENTION TESTS ---

test('only super admin can assign super admin role to staff', function () {
    // 1. Super admin CAN assign Super Admin role
    $superAdmin = Admin::factory()->superAdmin()->create();
    Mail::fake();

    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.staff')
        ->set('name', 'New Boss')
        ->set('email', 'boss@example.com')
        ->set('selectedRoles', ['Super Admin'])
        ->call('saveStaff')
        ->assertHasNoErrors();

    $newBoss = Admin::where('email', 'boss@example.com')->first();
    expect($newBoss)->not->toBeNull()
        ->and($newBoss->hasRole('Super Admin'))->toBeTrue();

    // 2. Non-super admin with admins.manage CANNOT assign Super Admin role
    $manager = Admin::factory()->create();
    $managerRole = Role::findOrCreate('Staff Admin', 'admin');
    $managerRole->syncPermissions([AdminPermissions::ADMINS_VIEW, AdminPermissions::ADMINS_MANAGE]);
    $manager->assignRole($managerRole);

    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.staff')
        ->set('name', 'Escalated Staff')
        ->set('email', 'escalate@example.com')
        ->set('selectedRoles', ['Super Admin'])
        ->call('saveStaff')
        ->assertHasErrors(['selectedRoles']);

    expect(Admin::where('email', 'escalate@example.com')->first())->toBeNull();
});

test('non-super admin cannot grant permissions they do not hold when managing roles', function () {
    // Manager has roles.manage and customers.view only
    $manager = Admin::factory()->create();
    $managerRole = Role::findOrCreate('Restricted Role Editor', 'admin');
    $managerRole->syncPermissions([AdminPermissions::ROLES_MANAGE, AdminPermissions::CUSTOMERS_VIEW]);
    $manager->assignRole($managerRole);

    // Manager attempts to create a role with sellers.kyc (which they DO NOT hold)
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.roles')
        ->set('name', 'Sneaky Role')
        ->set('selectedPermissions', [AdminPermissions::CUSTOMERS_VIEW, AdminPermissions::SELLERS_KYC])
        ->call('saveRole')
        ->assertHasErrors(['selectedPermissions']);

    expect(Role::where('name', 'Sneaky Role')->first())->toBeNull();

    // Manager CAN create a role with a subset of permissions they DO hold
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.roles')
        ->set('name', 'Customer Viewer Only')
        ->set('selectedPermissions', [AdminPermissions::CUSTOMERS_VIEW])
        ->call('saveRole')
        ->assertHasNoErrors();

    expect(Role::where('name', 'Customer Viewer Only')->first())->not->toBeNull();
});

test('non-super admin cannot assign a role whose permissions exceed their own subset', function () {
    // 1. Role containing sellers.manage
    $sellerRole = Role::findOrCreate('Seller Specialist', 'admin');
    $sellerRole->syncPermissions([AdminPermissions::SELLERS_MANAGE]);

    // 2. Manager has admins.manage and customers.view, but NOT sellers.manage
    $manager = Admin::factory()->create();
    $managerRole = Role::findOrCreate('Manager', 'admin');
    $managerRole->syncPermissions([AdminPermissions::ADMINS_VIEW, AdminPermissions::ADMINS_MANAGE, AdminPermissions::CUSTOMERS_VIEW]);
    $manager->assignRole($managerRole);

    // Attempting to assign Seller Specialist role to another admin
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.staff')
        ->set('name', 'Subordinate')
        ->set('email', 'subordinate@example.com')
        ->set('selectedRoles', [$sellerRole->name])
        ->call('saveStaff')
        ->assertHasErrors(['selectedRoles']);

    expect(Admin::where('email', 'subordinate@example.com')->first())->toBeNull();
});

test('super admin role name, permissions and deletion are strictly locked', function () {
    $superAdmin = Admin::factory()->superAdmin()->create();
    $superAdminRole = Role::where('name', 'Super Admin')->where('guard_name', 'admin')->first();

    // 1. Cannot open edit modal on Super Admin role
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.roles')
        ->call('openEditModal', $superAdminRole->id)
        ->assertHasErrors(['role']);

    // 2. Cannot name a new role "Super Admin"
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.roles')
        ->set('name', 'Super Admin')
        ->set('selectedPermissions', [AdminPermissions::CUSTOMERS_VIEW])
        ->call('saveRole')
        ->assertHasErrors(['name']);

    // 3. Cannot delete Super Admin role
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.roles')
        ->call('deleteRole', $superAdminRole->id);

    expect(Role::where('name', 'Super Admin')->where('guard_name', 'admin')->first())->not->toBeNull();
});

test('role deletion is blocked while assigned to staff members', function () {
    $superAdmin = Admin::factory()->superAdmin()->create();
    $customRole = Role::create(['name' => 'Support Agent', 'guard_name' => 'admin']);
    $customRole->syncPermissions([AdminPermissions::CUSTOMERS_VIEW]);

    $staff = Admin::factory()->create();
    $staff->assignRole($customRole);

    // Delete should fail
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.roles')
        ->call('deleteRole', $customRole->id);

    expect(Role::where('name', 'Support Agent')->first())->not->toBeNull();

    // Remove assignment from staff
    $staff->removeRole($customRole);

    // Now deletion succeeds
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.roles')
        ->call('deleteRole', $customRole->id);

    expect(Role::where('name', 'Support Agent')->first())->toBeNull();
});

// --- STAFF INVITATION, DEACTIVATION & SET-PASSWORD TESTS ---

test('staff invitation flow dispatches email and creates record with pending acceptance', function () {
    Mail::fake();
    $superAdmin = Admin::factory()->superAdmin()->create();

    $role = Role::findOrCreate('Catalog Specialist', 'admin');

    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.staff')
        ->set('name', 'Jane Staff')
        ->set('email', 'jane@example.com')
        ->set('selectedRoles', [$role->name])
        ->call('saveStaff')
        ->assertHasNoErrors();

    $newStaff = Admin::where('email', 'jane@example.com')->first();
    expect($newStaff)->not->toBeNull()
        ->and($newStaff->is_active)->toBeTrue()
        ->and($newStaff->invitation_accepted_at)->toBeNull()
        ->and($newStaff->hasRole('Catalog Specialist'))->toBeTrue();

    // Email queued
    Mail::assertQueued(StaffInvitationMail::class, function ($mail) {
        return $mail->hasTo('jane@example.com') && ! empty($mail->token);
    });

    // Password reset token exists in admin_password_reset_tokens
    $tokenRow = DB::table('admin_password_reset_tokens')->where('email', 'jane@example.com')->first();
    expect($tokenRow)->not->toBeNull();
});

test('set password sets invitation_accepted_at and never alters is_active', function () {
    $admin = Admin::factory()->create([
        'email' => 'invitee@example.com',
        'is_active' => true,
        'invitation_accepted_at' => null,
    ]);

    $token = Password::broker('admins')->createToken($admin);

    Livewire::withQueryParams(['email' => $admin->email])
        ->test('pages::admin.auth.set-password', ['token' => $token])
        ->set('password', 'SecretPass123#')
        ->set('password_confirmation', 'SecretPass123#')
        ->call('setPassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.dashboard'));

    $fresh = $admin->fresh();
    expect($fresh->invitation_accepted_at)->not->toBeNull()
        ->and($fresh->is_active)->toBeTrue();

    // Token was consumed
    expect(Password::broker('admins')->tokenExists($admin, $token))->toBeFalse();
});

test('deactivating staff deletes pending password reset tokens', function () {
    $superAdmin = Admin::factory()->superAdmin()->create();
    $staff = Admin::factory()->create(['email' => 'temp@example.com', 'is_active' => true]);

    $token = Password::broker('admins')->createToken($staff);
    expect(DB::table('admin_password_reset_tokens')->where('email', 'temp@example.com')->exists())->toBeTrue();

    // Deactivate staff
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.staff')
        ->call('toggleActive', $staff->id);

    expect($staff->fresh()->is_active)->toBeFalse();

    // Pending tokens were deleted
    expect(DB::table('admin_password_reset_tokens')->where('email', 'temp@example.com')->exists())->toBeFalse();
});

test('deactivated staff member cannot use old invite link to regain access', function () {
    $staff = Admin::factory()->create([
        'email' => 'deactivated@example.com',
        'is_active' => false,
        'invitation_accepted_at' => null,
    ]);

    // Even if an expired/stale token is submitted
    Livewire::withQueryParams(['email' => $staff->email])
        ->test('pages::admin.auth.set-password', ['token' => 'invalid-or-deleted-token'])
        ->set('password', 'SecretPass123#')
        ->set('password_confirmation', 'SecretPass123#')
        ->call('setPassword')
        ->assertHasErrors(['token']);

    expect(Auth::guard('admin')->check())->toBeFalse();
});

test('inactive staff is immediately logged out on next request via middleware', function () {
    $staff = Admin::factory()->superAdmin()->create(['is_active' => false]);

    $response = $this->actingAs($staff, 'admin')->get(route('admin.dashboard'));

    $response->assertRedirect(route('admin.login'));
    expect(Auth::guard('admin')->check())->toBeFalse();
});

test('last active super admin cannot be deactivated or demoted', function () {
    // Only 1 super admin exists
    $lastSuperAdmin = Admin::factory()->superAdmin()->create(['is_active' => true]);
    $secondAdmin = Admin::factory()->create();
    $secondAdminRole = Role::findOrCreate('Admin Manager', 'admin');
    $secondAdminRole->syncPermissions([AdminPermissions::ADMINS_VIEW, AdminPermissions::ADMINS_MANAGE]);
    $secondAdmin->assignRole($secondAdminRole);

    // 1. Deactivation attempt blocked
    Livewire::actingAs($secondAdmin, 'admin')
        ->test('pages::admin.staff')
        ->call('toggleActive', $lastSuperAdmin->id);

    expect($lastSuperAdmin->fresh()->is_active)->toBeTrue();

    // 2. Demotion attempt (removing Super Admin role) blocked
    Livewire::actingAs($lastSuperAdmin, 'admin')
        ->test('pages::admin.staff')
        ->call('openEditModal', $lastSuperAdmin->id)
        ->assertHasErrors(['staff']); // Can't edit self anyway

    $otherSuperAdmin = Admin::factory()->superAdmin()->create();
    // With 2 super admins, deactivation is permitted
    Livewire::actingAs($otherSuperAdmin, 'admin')
        ->test('pages::admin.staff')
        ->call('toggleActive', $lastSuperAdmin->id);

    expect($lastSuperAdmin->fresh()->is_active)->toBeFalse();
});

test('staff cannot edit their own roles or deactivate themselves', function () {
    $staff = Admin::factory()->superAdmin()->create();

    // 1. Self edit blocked
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.staff')
        ->call('openEditModal', $staff->id)
        ->assertHasErrors(['staff']);

    // 2. Self deactivation blocked
    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.staff')
        ->call('toggleActive', $staff->id);

    expect($staff->fresh()->is_active)->toBeTrue();
});

// --- KYC MASKING TESTS ---

test('without sellers.kyc permission PAN GSTIN and bank account are masked and docs blocked', function () {
    $staff = Admin::factory()->create();
    $role = Role::findOrCreate('Regular Reviewer', 'admin');
    // Only sellers.view - no sellers.kyc
    $role->syncPermissions([AdminPermissions::SELLERS_VIEW]);
    $staff->assignRole($role);

    $seller = SellerProfile::factory()->create([
        'pan' => 'ABCDE1234F',
        'gstin' => '22AAAAA0000A1Z5',
        'bank_account_number' => '123456789012',
        'ifsc' => 'HDFC0001234',
    ]);

    SellerDocument::create([
        'seller_profile_id' => $seller->id,
        'type' => 'pan_card',
        'path' => 'kyc/test.pdf',
        'original_name' => 'pan.pdf',
    ]);

    $response = $this->actingAs($staff, 'admin')->get(route('admin.sellers.show', $seller));

    $response->assertOk()
        // Last 4 of PAN visible (234F), previous masked
        ->assertSee('••••••234F')
        ->assertDontSee('ABCDE1234F')
        // Last 4 of GSTIN visible (A1Z5), previous masked
        ->assertSee('•••••••••••A1Z5')
        ->assertDontSee('22AAAAA0000A1Z5')
        // Last 4 of Bank visible (9012), previous masked
        ->assertSee('••••••••9012')
        ->assertDontSee('123456789012')
        // Document locked indicator
        ->assertSee('Locked')
        ->assertDontSee('data-test="kyc-doc-download"', false);
});

test('with sellers.kyc permission PAN GSTIN and bank account are fully visible and documents downloadable', function () {
    $staff = Admin::factory()->create();
    $role = Role::findOrCreate('KYC Auditor', 'admin');
    $role->syncPermissions([AdminPermissions::SELLERS_VIEW, AdminPermissions::SELLERS_KYC]);
    $staff->assignRole($role);

    $seller = SellerProfile::factory()->create([
        'pan' => 'ABCDE1234F',
        'gstin' => '22AAAAA0000A1Z5',
        'bank_account_number' => '123456789012',
        'ifsc' => 'HDFC0001234',
    ]);

    SellerDocument::create([
        'seller_profile_id' => $seller->id,
        'type' => 'pan_card',
        'path' => 'kyc/test.pdf',
        'original_name' => 'pan.pdf',
    ]);

    $response = $this->actingAs($staff, 'admin')->get(route('admin.sellers.show', $seller));

    $response->assertOk()
        ->assertSee('ABCDE1234F')
        ->assertSee('22AAAAA0000A1Z5')
        ->assertSee('123456789012')
        ->assertDontSee('Masked (KYC permission required)')
        ->assertSee('data-test="kyc-doc-download"', false);
});

// --- PERMISSION BOUNDARY & MANAGEMENT PROTECTION TESTS ---

test('non-super-admin cannot edit, deactivate, or resend invites to staff holding permissions outside their authority or holding super admin', function () {
    // 1. Manager with admins.view, admins.manage, customers.view
    $manager = Admin::factory()->create();
    $managerRole = Role::findOrCreate('Staff Ops', 'admin');
    $managerRole->syncPermissions([AdminPermissions::ADMINS_VIEW, AdminPermissions::ADMINS_MANAGE, AdminPermissions::CUSTOMERS_VIEW]);
    $manager->assignRole($managerRole);

    // 2. Target staff holding sellers.manage (not held by manager)
    $specialist = Admin::factory()->create(['email' => 'specialist@example.com']);
    $specialistRole = Role::findOrCreate('Seller Handler', 'admin');
    $specialistRole->syncPermissions([AdminPermissions::SELLERS_MANAGE]);
    $specialist->assignRole($specialistRole);

    // 3. Target staff holding Super Admin
    $superAdmin = Admin::factory()->superAdmin()->create(['email' => 'supreme@example.com']);

    // Attempt to edit specialist -> fails
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.staff')
        ->call('openEditModal', $specialist->id)
        ->assertHasErrors(['staff']);

    // Attempt to edit super admin -> fails
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.staff')
        ->call('openEditModal', $superAdmin->id)
        ->assertHasErrors(['staff']);

    // Attempt to deactivate specialist -> fails
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.staff')
        ->call('toggleActive', $specialist->id);
    expect($specialist->fresh()->is_active)->toBeTrue();

    // Attempt to resend invite to specialist -> fails
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.staff')
        ->call('resendInvite', $specialist->id);
    expect(DB::table('admin_password_reset_tokens')->where('email', $specialist->email)->exists())->toBeFalse();

    // 4. Target staff holding a subset or equal permissions (customers.view) CAN be managed
    $junior = Admin::factory()->create(['email' => 'junior@example.com']);
    $juniorRole = Role::findOrCreate('Junior Support', 'admin');
    $juniorRole->syncPermissions([AdminPermissions::CUSTOMERS_VIEW]);
    $junior->assignRole($juniorRole);

    // Junior can be edited
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.staff')
        ->call('openEditModal', $junior->id)
        ->assertHasNoErrors()
        ->set('name', 'Junior Updated')
        ->call('saveStaff')
        ->assertHasNoErrors();

    expect($junior->fresh()->name)->toBe('Junior Updated');

    // Junior can be deactivated and reactivated
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.staff')
        ->call('toggleActive', $junior->id);
    expect($junior->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.staff')
        ->call('toggleActive', $junior->id);
    expect($junior->fresh()->is_active)->toBeTrue();
});

test('non-super-admin cannot edit or delete roles whose permissions exceed their own subset', function () {
    // Manager has roles.manage and customers.view
    $manager = Admin::factory()->create();
    $managerRole = Role::findOrCreate('Limited Role Admin', 'admin');
    $managerRole->syncPermissions([AdminPermissions::ROLES_MANAGE, AdminPermissions::CUSTOMERS_VIEW]);
    $manager->assignRole($managerRole);

    // Role with sellers.kyc (outside manager's authority)
    $financeRole = Role::create(['name' => 'Finance KYC', 'guard_name' => 'admin']);
    $financeRole->syncPermissions([AdminPermissions::SELLERS_KYC]);

    // Role with customers.view (within manager's authority)
    $supportRole = Role::create(['name' => 'Customer Rep', 'guard_name' => 'admin']);
    $supportRole->syncPermissions([AdminPermissions::CUSTOMERS_VIEW]);

    // Attempt to edit role with excess permissions -> blocked
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.roles')
        ->call('openEditModal', $financeRole->id)
        ->assertHasErrors(['role']);

    // Attempt to delete role with excess permissions -> blocked
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.roles')
        ->call('deleteRole', $financeRole->id);
    expect(Role::where('name', 'Finance KYC')->first())->not->toBeNull();

    // Role within manager's subset CAN be edited and deleted
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.roles')
        ->call('openEditModal', $supportRole->id)
        ->assertHasNoErrors()
        ->set('name', 'Customer Specialist')
        ->call('saveRole')
        ->assertHasNoErrors();

    expect(Role::where('name', 'Customer Specialist')->first())->not->toBeNull();

    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.roles')
        ->call('deleteRole', $supportRole->id);
    expect(Role::where('name', 'Customer Specialist')->first())->toBeNull();
});

// --- PERMISSIONS SYNC PRUNE TESTS ---

test('permissions sync without prune preserves extra permissions, and prune flag removes them', function () {
    // 1. Create an obsolete/legacy permission
    Permission::findOrCreate('legacy.deprecated_perm', 'admin');

    // 2. Run sync WITHOUT --prune
    Artisan::call('permissions:sync');

    // Legacy permission still exists
    expect(Permission::where('name', 'legacy.deprecated_perm')->where('guard_name', 'admin')->exists())->toBeTrue();

    // 3. Run sync WITH --prune
    Artisan::call('permissions:sync', ['--prune' => true]);

    // Legacy permission has now been pruned
    expect(Permission::where('name', 'legacy.deprecated_perm')->where('guard_name', 'admin')->exists())->toBeFalse();
});

// --- COMPREHENSIVE ACTIVITY LOGGING TESTS ---

test('activity log records seller approve, reject, suspend, and reactivate', function () {
    $superAdmin = Admin::factory()->superAdmin()->create();
    $seller = SellerProfile::factory()->pending()->create(['store_name' => 'Toy Galaxy']);

    // Approve
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.sellers-show', ['sellerProfile' => $seller])
        ->call('approveApplication');

    expect(AdminActivityLog::where('action', 'seller.approved')->where('subject_id', $seller->id)->exists())->toBeTrue();

    // Reject (another seller)
    $seller2 = SellerProfile::factory()->pending()->create(['store_name' => 'Bad Toys']);
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.sellers-show', ['sellerProfile' => $seller2])
        ->set('rejection_reason', 'Incomplete KYC documentation.')
        ->call('rejectApplication');

    expect(AdminActivityLog::where('action', 'seller.rejected')->where('subject_id', $seller2->id)->exists())->toBeTrue();

    // Suspend
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.sellers-show', ['sellerProfile' => $seller->fresh()])
        ->call('suspendSeller');

    expect(AdminActivityLog::where('action', 'seller.suspended')->where('subject_id', $seller->id)->exists())->toBeTrue();

    // Reactivate
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.sellers-show', ['sellerProfile' => $seller->fresh()])
        ->call('reactivateSeller');

    expect(AdminActivityLog::where('action', 'seller.reactivated')->where('subject_id', $seller->id)->exists())->toBeTrue();
});

test('activity log records product QC approve and reject', function () {
    $superAdmin = Admin::factory()->superAdmin()->create();
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
    ]);

    $cat = Category::create(['name' => 'Toys']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Blocks', 'hsn_code' => '9503', 'gst_rate' => 12.0]);
    $brand = Brand::create(['name' => 'LegoClone', 'status' => BrandStatus::Approved]);
    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Building Blocks Set',
        'description' => '500 piece blocks',
        'created_by' => $seller->id,
        'qc_status' => ProductQcStatus::QcPending,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => 'BLOKS123456789',
        'variant_key' => 'color:red',
        'variant_attributes' => ['color' => 'red'],
        'qc_status' => VariantQcStatus::QcPending,
    ]);

    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SKU-BLOCKS-RED',
        'mrp' => 1000,
        'selling_price' => 800,
        'stock' => 5,
        'status' => ListingStatus::Draft,
    ]);

    // Approve
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.catalog.products')
        ->call('approveProduct', $product->id);

    expect(AdminActivityLog::where('action', 'product.qc_approved')->where('subject_id', $product->id)->exists())->toBeTrue();

    // Reject (another product)
    $product2 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Dangerous Toy',
        'description' => 'Unsafe edges',
        'created_by' => $seller->id,
        'qc_status' => ProductQcStatus::QcPending,
    ]);

    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.catalog.products')
        ->call('openRejectModal', $product2->id)
        ->set('rejection_reason', 'Choking hazard without safety label')
        ->call('confirmReject');

    expect(AdminActivityLog::where('action', 'product.qc_rejected')->where('subject_id', $product2->id)->exists())->toBeTrue();
});

test('activity log records brand approve and reject', function () {
    $superAdmin = Admin::factory()->superAdmin()->create();
    $brand = Brand::create(['name' => 'EcoToys', 'status' => BrandStatus::Pending]);

    // Approve
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.catalog.brands')
        ->call('approveBrand', $brand->id);

    expect(AdminActivityLog::where('action', 'brand.approved')->where('subject_id', $brand->id)->exists())->toBeTrue();

    // Reject
    $brand2 = Brand::create(['name' => 'FakeBrand', 'status' => BrandStatus::Pending]);
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.catalog.brands')
        ->call('openRejectModal', $brand2->id)
        ->set('rejection_reason', 'Infringing trademark')
        ->call('confirmReject');

    expect(AdminActivityLog::where('action', 'brand.rejected')->where('subject_id', $brand2->id)->exists())->toBeTrue();
});

test('activity log records customer activate and deactivate', function () {
    $superAdmin = Admin::factory()->superAdmin()->create();
    $customer = User::factory()->create(['is_active' => true]);

    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.customers')
        ->call('toggleActive', $customer->id);

    expect(AdminActivityLog::where('action', 'customer.deactivated')->where('subject_id', $customer->id)->exists())->toBeTrue();

    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.customers')
        ->call('toggleActive', $customer->id);

    expect(AdminActivityLog::where('action', 'customer.activated')->where('subject_id', $customer->id)->exists())->toBeTrue();
});

test('activity log records KYC document views', function () {
    Storage::fake('local');
    $superAdmin = Admin::factory()->superAdmin()->create();
    $seller = SellerProfile::factory()->create();

    Storage::disk('local')->put('kyc/gst.pdf', 'fake gst content');
    $doc = SellerDocument::create([
        'seller_profile_id' => $seller->id,
        'type' => 'gst_certificate',
        'path' => 'kyc/gst.pdf',
        'original_name' => 'gst.pdf',
    ]);

    $response = $this->actingAs($superAdmin, 'admin')->get(route('admin.sellers.documents.show', $doc));
    $response->assertOk();

    expect(AdminActivityLog::where('action', 'seller.kyc_document_viewed')->where('subject_id', $doc->id)->exists())->toBeTrue();
});
