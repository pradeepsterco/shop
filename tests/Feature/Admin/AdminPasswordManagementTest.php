<?php

use App\Mail\CustomerWelcomeMail;
use App\Mail\PasswordChangedByAdminMail;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\User;
use App\Support\AdminPermissions;
use App\Support\PasswordGenerator;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Artisan::call('permissions:sync');
    session()->flush();
});

// --- 1. GENERATED PASSWORD TESTS ---

test('generated password passes Password::defaults() and contains required complexity', function () {
    $pwd = PasswordGenerator::generate(16);

    expect(strlen($pwd))->toBeGreaterThanOrEqual(16);

    $validator = validator(
        ['password' => $pwd],
        ['password' => ['required', PasswordRule::defaults()]]
    );

    expect($validator->passes())->toBeTrue();
});

// --- 2. ADMIN CREATES STAFF WITH PASSWORD ---

test('admin can create staff with password directly and require password change on first login', function () {
    $superAdmin = Admin::factory()->superAdmin()->create();
    $role = Role::findOrCreate('Support Agent', 'admin');

    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.staff')
        ->call('openInviteModal')
        ->set('creationMode', 'password')
        ->set('name', 'Direct Staff')
        ->set('email', 'directstaff@example.com')
        ->set('selectedRoles', ['Support Agent'])
        ->call('generateStaffPassword')
        ->set('mustChangePassword', true)
        ->call('saveStaff')
        ->assertHasNoErrors();

    $newStaff = Admin::where('email', 'directstaff@example.com')->first();
    expect($newStaff)->not->toBeNull()
        ->and($newStaff->name)->toBe('Direct Staff')
        ->and($newStaff->invitation_accepted_at)->not->toBeNull()
        ->and($newStaff->must_change_password)->toBeTrue()
        ->and($newStaff->hasRole('Support Agent'))->toBeTrue();

    // Verify activity log without secret leakage
    $log = AdminActivityLog::where('action', 'staff.created')->where('subject_id', $newStaff->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->properties['email'])->toBe('directstaff@example.com')
        ->and($log->properties)->not->toHaveKey('password');
});

// --- 3. ADMIN CREATES CUSTOMER ---

test('admin can create customer with welcome set-password link with 24-hour expiry', function () {
    $admin = Admin::factory()->superAdmin()->create();
    Mail::fake();

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.customers')
        ->call('openCreateCustomerModal')
        ->set('creationMode', 'link')
        ->set('name', 'Welcome Customer')
        ->set('email', 'welcome@example.com')
        ->set('phone', '9811122233')
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $customer = User::where('email', 'welcome@example.com')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->name)->toBe('Welcome Customer')
        ->and($customer->phone)->toBe('9811122233')
        ->and($customer->email_verified_at)->toBeNull();

    Mail::assertQueued(CustomerWelcomeMail::class, function ($mail) use ($customer) {
        return $mail->user->id === $customer->id && $mail->hasTo('welcome@example.com');
    });

    // Check config expiry is 24 hours (1440 minutes) for users_welcome and 60 minutes for users
    expect(config('auth.passwords.users_welcome.expire'))->toBe(1440)
        ->and(config('auth.passwords.users.expire'))->toBe(60);

    // Verify token exists in customer_welcome_tokens table
    expect(DB::table('customer_welcome_tokens')->where('email', 'welcome@example.com')->exists())->toBeTrue();

    $log = AdminActivityLog::where('action', 'customer.created')->where('subject_id', $customer->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->properties['email'])->toBe('welcome@example.com')
        ->and($log->properties)->not->toHaveKey('password')
        ->and($log->properties)->not->toHaveKey('token');
});

test('admin can create customer with password directly and mark email as verified', function () {
    $admin = Admin::factory()->superAdmin()->create();
    Notification::fake();

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.customers')
        ->call('openCreateCustomerModal')
        ->set('creationMode', 'password')
        ->set('name', 'Verified Customer')
        ->set('email', 'verified@example.com')
        ->set('password', 'SecretPassword123!@#')
        ->set('password_confirmation', 'SecretPassword123!@#')
        ->set('markEmailVerified', true)
        ->set('mustChangePassword', true)
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $customer = User::where('email', 'verified@example.com')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->email_verified_at)->not->toBeNull()
        ->and($customer->must_change_password)->toBeTrue()
        ->and(Hash::check('SecretPassword123!@#', $customer->password))->toBeTrue();

    // No email verification sent because already marked verified
    Notification::assertNothingSent();
});

test('admin creating customer with password sends verification notification if not marked verified', function () {
    $admin = Admin::factory()->superAdmin()->create();
    Notification::fake();

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.customers')
        ->call('openCreateCustomerModal')
        ->set('creationMode', 'password')
        ->set('name', 'Unverified Customer')
        ->set('email', 'unverified@example.com')
        ->set('password', 'SecretPassword123!@#')
        ->set('password_confirmation', 'SecretPassword123!@#')
        ->set('markEmailVerified', false)
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $customer = User::where('email', 'unverified@example.com')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->email_verified_at)->toBeNull();

    Notification::assertSentTo($customer, VerifyEmail::class);
});

test('admin creating customer enforces Fortify validation rules and unique phone', function () {
    $admin = Admin::factory()->superAdmin()->create();
    User::factory()->create(['email' => 'duplicate@example.com', 'phone' => '9998887776']);

    // Duplicate email and duplicate phone
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.customers')
        ->call('openCreateCustomerModal')
        ->set('name', '')
        ->set('email', 'duplicate@example.com')
        ->set('phone', '9998887776')
        ->set('creationMode', 'password')
        ->set('password', 'short')
        ->set('password_confirmation', 'mismatch')
        ->call('saveCustomer')
        ->assertHasErrors(['name', 'email', 'phone', 'password']);
});

test('normal forgot-password tokens expire after 60 minutes', function () {
    $user = User::factory()->create([
        'password' => Hash::make('OldPassword123!@#'),
    ]);

    $token = Password::broker('users')->createToken($user);
    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeTrue();

    // 1. Travel 61 minutes into the future
    $this->travel(61)->minutes();

    $response = $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewPassword123!@#',
        'password_confirmation' => 'NewPassword123!@#',
    ]);

    $response->assertSessionHasErrors('email');
    expect(Hash::check('OldPassword123!@#', $user->fresh()->password))->toBeTrue();

    // 2. Travel back and test that a fresh token within 50 minutes succeeds
    $this->travelBack();

    $freshToken = Password::broker('users')->createToken($user);
    $this->travel(50)->minutes();

    $successResponse = $this->post(route('password.update'), [
        'token' => $freshToken,
        'email' => $user->email,
        'password' => 'NewPassword123!@#',
        'password_confirmation' => 'NewPassword123!@#',
    ]);

    $successResponse->assertSessionHasNoErrors();
    expect(Hash::check('NewPassword123!@#', $user->fresh()->password))->toBeTrue();

    $this->travelBack();
});

test('admin-created customer welcome link page sets password with 24-hour expiry', function () {
    $customer = User::factory()->create([
        'password' => Hash::make('InitialPassword123!@#'),
    ]);

    $welcomeToken = Password::broker('users_welcome')->createToken($customer);
    expect(DB::table('customer_welcome_tokens')->where('email', $customer->email)->exists())->toBeTrue();

    // 1. Travel 120 minutes (2 hours) - past normal 60 min expiry
    $this->travel(120)->minutes();

    // Dedicated welcome page accepts the token and sets password
    Livewire::withQueryParams(['token' => $welcomeToken, 'email' => $customer->email])
        ->test('pages::auth.welcome')
        ->assertSet('isTokenValid', true)
        ->set('password', 'WelcomeSetPass123!@#')
        ->set('password_confirmation', 'WelcomeSetPass123!@#')
        ->call('setPassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect(Hash::check('WelcomeSetPass123!@#', $customer->fresh()->password))->toBeTrue();
    expect(DB::table('customer_welcome_tokens')->where('email', $customer->email)->exists())->toBeFalse();

    $this->travelBack();

    // 2. Test expiration after 1440 minutes (24 hours + 1 min)
    $welcomeToken2 = Password::broker('users_welcome')->createToken($customer);
    $this->travel(1441)->minutes();

    Livewire::withQueryParams(['token' => $welcomeToken2, 'email' => $customer->email])
        ->test('pages::auth.welcome')
        ->assertSet('isTokenValid', false)
        ->set('password', 'TooLatePass123!@#')
        ->set('password_confirmation', 'TooLatePass123!@#')
        ->call('setPassword')
        ->assertHasErrors(['token']);

    expect(Hash::check('WelcomeSetPass123!@#', $customer->fresh()->password))->toBeTrue();

    $this->travelBack();
});

test('customer with pending welcome token can still use normal forgot-password', function () {
    $customer = User::factory()->create([
        'password' => Hash::make('InitialPassword123!@#'),
    ]);

    // Customer has pending welcome token in customer_welcome_tokens
    $welcomeToken = Password::broker('users_welcome')->createToken($customer);
    expect(DB::table('customer_welcome_tokens')->where('email', $customer->email)->exists())->toBeTrue();

    // Customer requests a standard forgot password reset link
    $this->post(route('password.email'), [
        'email' => $customer->email,
    ])->assertSessionHas('status');

    // Both tokens exist independently in their respective tables
    expect(DB::table('customer_welcome_tokens')->where('email', $customer->email)->exists())->toBeTrue();
    expect(DB::table('password_reset_tokens')->where('email', $customer->email)->exists())->toBeTrue();

    // Retrieve the normal token from password_reset_tokens table
    $resetRecord = DB::table('password_reset_tokens')->where('email', $customer->email)->first();
    expect($resetRecord)->not->toBeNull();

    // Normal reset via /reset-password route works with users broker
    $resetToken = Password::broker('users')->createToken($customer);
    $response = $this->post(route('password.update'), [
        'token' => $resetToken,
        'email' => $customer->email,
        'password' => 'NormalForgotPass123!@#',
        'password_confirmation' => 'NormalForgotPass123!@#',
    ]);

    $response->assertSessionHasNoErrors();
    expect(Hash::check('NormalForgotPass123!@#', $customer->fresh()->password))->toBeTrue();
    // Normal reset token is consumed
    expect(DB::table('password_reset_tokens')->where('email', $customer->email)->exists())->toBeFalse();
    // Welcome token remains in customer_welcome_tokens
    expect(DB::table('customer_welcome_tokens')->where('email', $customer->email)->exists())->toBeTrue();
});

// --- 4. STAFF PASSWORD RESET & PRIVILEGE ESCALATION ---

test('only super admin can reset super admin password', function () {
    $superAdmin1 = Admin::factory()->superAdmin()->create();
    $superAdmin2 = Admin::factory()->superAdmin()->create();

    // Regular manager with admins.manage and admins.password
    $manager = Admin::factory()->create();
    $role = Role::findOrCreate('Staff Admin', 'admin');
    $role->syncPermissions([AdminPermissions::ADMINS_VIEW, AdminPermissions::ADMINS_MANAGE, AdminPermissions::ADMINS_PASSWORD]);
    $manager->assignRole($role);

    // 1. Manager CANNOT reset Super Admin 2
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.staff')
        ->call('openResetPasswordModal', $superAdmin2->id);

    // 2. Manager cannot confirm reset for Super Admin 2
    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.staff')
        ->set('resetAdminId', $superAdmin2->id)
        ->set('resetMode', 'password')
        ->set('newPassword', 'NewSecurePass123!@#')
        ->set('newPassword_confirmation', 'NewSecurePass123!@#')
        ->call('confirmResetPassword');

    // Super Admin 2's password is unchanged
    expect(Hash::check('NewSecurePass123!@#', $superAdmin2->fresh()->password))->toBeFalse();

    // 3. Super Admin 1 CAN reset Super Admin 2
    Mail::fake();
    Livewire::actingAs($superAdmin1, 'admin')
        ->test('pages::admin.staff')
        ->call('openResetPasswordModal', $superAdmin2->id)
        ->set('resetMode', 'password')
        ->set('newPassword', 'NewSecurePass123!@#')
        ->set('newPassword_confirmation', 'NewSecurePass123!@#')
        ->call('confirmResetPassword')
        ->assertHasNoErrors();

    expect(Hash::check('NewSecurePass123!@#', $superAdmin2->fresh()->password))->toBeTrue();
    Mail::assertQueued(PasswordChangedByAdminMail::class);
});

test('staff cannot reset their own password from the staff screen', function () {
    $superAdmin = Admin::factory()->superAdmin()->create();

    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.staff')
        ->call('openResetPasswordModal', $superAdmin->id)
        ->set('resetAdminId', $superAdmin->id)
        ->set('resetMode', 'password')
        ->set('newPassword', 'NewPass123456!@#')
        ->set('newPassword_confirmation', 'NewPass123456!@#')
        ->call('confirmResetPassword');

    expect(Hash::check('NewPass123456!@#,', $superAdmin->fresh()->password))->toBeFalse();
});

test('non-super-admin can reset staff password within subset permissions', function () {
    $manager = Admin::factory()->create();
    $managerRole = Role::findOrCreate('Manager', 'admin');
    $managerRole->syncPermissions([
        AdminPermissions::ADMINS_VIEW,
        AdminPermissions::ADMINS_MANAGE,
        AdminPermissions::ADMINS_PASSWORD,
        AdminPermissions::CUSTOMERS_VIEW,
    ]);
    $manager->assignRole($managerRole);

    $subordinate = Admin::factory()->create();
    $subRole = Role::findOrCreate('Customer Viewer', 'admin');
    $subRole->syncPermissions([AdminPermissions::CUSTOMERS_VIEW]);
    $subordinate->assignRole($subRole);

    Mail::fake();

    Livewire::actingAs($manager, 'admin')
        ->test('pages::admin.staff')
        ->call('openResetPasswordModal', $subordinate->id)
        ->set('resetMode', 'password')
        ->set('newPassword', 'ValidSubPass123!@#')
        ->set('newPassword_confirmation', 'ValidSubPass123!@#')
        ->call('confirmResetPassword')
        ->assertHasNoErrors();

    expect(Hash::check('ValidSubPass123!@#', $subordinate->fresh()->password))->toBeTrue();
});

// --- 5. CUSTOMER PASSWORD RESET ---

test('admin can reset customer password and clear sessions table for that user_id', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $customer = User::factory()->create();
    Mail::fake();

    // Insert dummy session for this customer
    DB::table('sessions')->insert([
        'id' => 'cust-session-123',
        'user_id' => $customer->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
        'payload' => 'dummy',
        'last_activity' => time(),
    ]);

    expect(DB::table('sessions')->where('user_id', $customer->id)->exists())->toBeTrue();

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.customers')
        ->call('openResetPasswordModal', $customer->id)
        ->set('resetMode', 'password')
        ->set('newPassword', 'NewCustomerPass123!@#')
        ->set('newPassword_confirmation', 'NewCustomerPass123!@#')
        ->set('resetMustChangePassword', true)
        ->call('confirmResetPassword')
        ->assertHasNoErrors();

    $freshCustomer = $customer->fresh();
    expect(Hash::check('NewCustomerPass123!@#', $freshCustomer->password))->toBeTrue()
        ->and($freshCustomer->must_change_password)->toBeTrue()
        ->and($freshCustomer->remember_token)->toBeNull();

    // Sessions deleted for this customer
    expect(DB::table('sessions')->where('user_id', $customer->id)->exists())->toBeFalse();

    Mail::assertQueued(PasswordChangedByAdminMail::class);

    $log = AdminActivityLog::where('action', 'customer.password_reset')->where('subject_id', $customer->id)->first();
    expect($log)->not->toBeNull();
});

// --- 6. DUAL SESSION INDEPENDENCE: RESET ADMIN #N DOES NOT AFFECT CUSTOMER #N ---

test('resetting admin #N does not affect customer #N or delete customer #N sessions', function () {
    $superAdmin = Admin::factory()->superAdmin()->create();

    // Create a customer with id = 100
    $customer = User::factory()->create(['id' => 100, 'password' => Hash::make('CustOldPassword123!@#')]);

    // Create an admin with id = 100
    $targetAdmin = Admin::factory()->create(['id' => 100, 'password' => Hash::make('AdminOldPassword123!@#')]);

    // Insert session for customer #100
    DB::table('sessions')->insert([
        'id' => 'session-cust-100',
        'user_id' => 100,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
        'payload' => 'payload100',
        'last_activity' => time(),
    ]);

    // Super Admin resets Admin #100 password
    Livewire::actingAs($superAdmin, 'admin')
        ->test('pages::admin.staff')
        ->call('openResetPasswordModal', $targetAdmin->id)
        ->set('resetMode', 'password')
        ->set('newPassword', 'NewAdminPass123!@#')
        ->set('newPassword_confirmation', 'NewAdminPass123!@#')
        ->call('confirmResetPassword')
        ->assertHasNoErrors();

    // 1. Target admin password changed
    expect(Hash::check('NewAdminPass123!@#', $targetAdmin->fresh()->password))->toBeTrue();

    // 2. Customer #100 password is UNTOUCHED
    expect(Hash::check('CustOldPassword123!@#', $customer->fresh()->password))->toBeTrue();

    // 3. Customer #100 session is STILL PRESENT in sessions table (Requirement 1: do NOT delete rows for admins)
    expect(DB::table('sessions')->where('id', 'session-cust-100')->exists())->toBeTrue();
});

// --- 7. AUTHENTICATESESSION ON LIVEWIRE UPDATE AND OTHER SESSIONS ---

test('AuthenticateSession rejects customer with stale password hash on second request and Livewire update', function () {
    $customer = User::factory()->create(['password' => Hash::make('OriginalPassword123!@#')]);

    // Simulate session with password hash of original password
    $session = [
        'password_hash_web' => $customer->getAuthPassword(),
    ];

    // Password is changed
    $customer->update(['password' => Hash::make('BrandNewPassword123!@#')]);

    // Request 1: A Livewire update request with the old session is rejected
    $updateUri = Livewire::getUpdateUri();
    $livewireResponse = $this->withSession($session)
        ->actingAs($customer, 'web')
        ->post($updateUri, [
            'components' => [],
        ]);
    $livewireResponse->assertRedirect(route('login'));

    // Request 2: A second request to an authenticated page with old session is also rejected
    $response = $this->withSession($session)
        ->actingAs($customer, 'web')
        ->get(route('dashboard'));

    $response->assertRedirect(route('login'));
});

test('AuthenticateAdminSession rejects admin with stale password hash on second request', function () {
    $admin = Admin::factory()->superAdmin()->create(['password' => Hash::make('OriginalAdminPass123!@#')]);

    $adminHash = Auth::guard('admin')->hashPasswordForCookie($admin->getAuthPassword());
    $session = [
        'password_hash_admin' => $adminHash,
        'password_hash_admin_id' => $admin->id,
    ];

    // Password is changed
    $admin->update(['password' => Hash::make('BrandNewAdminPass123!@#')]);

    // Request 1: First request to an authenticated admin route with stale session is rejected
    $response1 = $this->withSession($session)
        ->actingAs($admin, 'admin')
        ->get(route('admin.customers'));

    $response1->assertRedirect(route('admin.login'));

    // Request 2: A second request to an authenticated admin route with old session is also rejected
    $response2 = $this->withSession($session)
        ->actingAs($admin, 'admin')
        ->get(route('admin.dashboard'));

    $response2->assertRedirect(route('admin.login'));
});

// --- 8. FORCE PASSWORD CHANGE LIFECYCLE ---

test('force password change blocks access to customer and seller routes until changed', function () {
    $user = User::factory()->create([
        'must_change_password' => true,
        'password' => Hash::make('InitialPassword123!@#'),
        'email_verified_at' => now(),
    ]);

    // Customer route (/dashboard) is blocked
    $response = $this->actingAs($user, 'web')->get(route('dashboard'));
    $response->assertRedirect(route('password.force-change'));

    // Seller route (/seller/apply) is blocked
    $sellerResponse = $this->actingAs($user, 'web')->get(route('seller.apply'));
    $sellerResponse->assertRedirect(route('password.force-change'));

    // Force change page is accessible
    $allowedResponse = $this->actingAs($user, 'web')->get(route('password.force-change'));
    $allowedResponse->assertOk();

    // Submitting identical password is rejected
    Livewire::actingAs($user, 'web')
        ->test('pages::auth.force-change-password')
        ->set('password', 'InitialPassword123!@#')
        ->set('password_confirmation', 'InitialPassword123!@#')
        ->call('changePassword')
        ->assertHasErrors(['password']);

    // Submitting different valid password succeeds
    Livewire::actingAs($user, 'web')
        ->test('pages::auth.force-change-password')
        ->set('password', 'UpdatedPassword999!@#')
        ->set('password_confirmation', 'UpdatedPassword999!@#')
        ->call('changePassword')
        ->assertHasNoErrors();

    $freshUser = $user->fresh();
    expect($freshUser->must_change_password)->toBeFalse()
        ->and(Hash::check('UpdatedPassword999!@#', $freshUser->password))->toBeTrue();

    // Now dashboard is accessible
    $dashboardResponse = $this->withSession(['password_hash_web' => $freshUser->getAuthPassword()])
        ->actingAs($freshUser, 'web')
        ->get(route('dashboard'));
    $dashboardResponse->assertOk();
});

test('force password change blocks admin routes until changed', function () {
    $this->flushSession();

    $admin = Admin::factory()->superAdmin()->create([
        'must_change_password' => true,
        'password' => Hash::make('AdminInitialPass123!@#'),
    ]);

    // Admin protected route is redirected to force change
    $response = $this->actingAs($admin, 'admin')->get(route('admin.customers'));
    $response->assertRedirect(route('admin.password.force-change'));

    // Force change route is accessible
    $fcResp = $this->actingAs($admin, 'admin')->get(route('admin.password.force-change'));
    $fcResp->assertOk();

    // Submitting same password is rejected
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.auth.force-change-password')
        ->set('password', 'AdminInitialPass123!@#')
        ->set('password_confirmation', 'AdminInitialPass123!@#')
        ->call('changePassword')
        ->assertHasErrors(['password']);

    // Submitting new password clears must_change_password
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.auth.force-change-password')
        ->set('password', 'BrandNewAdminPass999!@#')
        ->set('password_confirmation', 'BrandNewAdminPass999!@#')
        ->call('changePassword')
        ->assertHasNoErrors();

    expect($admin->fresh()->must_change_password)->toBeFalse();

    // Admin can now access customers
    $this->withSession(['password_hash_admin' => $admin->fresh()->getAuthPassword()])
        ->actingAs($admin->fresh(), 'admin')
        ->get(route('admin.customers'))
        ->assertOk();
});

// --- 9. LIVEWIRE STATE HYGIENE (SECRETS NEVER REMAIN IN SNAPSHOT) ---

test('generated and entered passwords are wiped from Livewire state on close and save', function () {
    $admin = Admin::factory()->superAdmin()->create();

    // In staff component
    $staffComponent = Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.staff')
        ->call('openInviteModal')
        ->set('creationMode', 'password')
        ->call('generateStaffPassword');

    expect($staffComponent->get('generatedPassword'))->not->toBeNull();

    $staffComponent->call('closeStaffModal');
    expect($staffComponent->get('password'))->toBe('')
        ->and($staffComponent->get('password_confirmation'))->toBe('')
        ->and($staffComponent->get('generatedPassword'))->toBeNull();

    // In customer component
    $custComponent = Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.customers')
        ->call('openCreateCustomerModal')
        ->set('creationMode', 'password')
        ->call('generateCustomerPassword');

    expect($custComponent->get('generatedPassword'))->not->toBeNull();

    $custComponent->call('closeCreateCustomerModal');
    expect($custComponent->get('password'))->toBe('')
        ->and($custComponent->get('password_confirmation'))->toBe('')
        ->and($custComponent->get('generatedPassword'))->toBeNull();
});
