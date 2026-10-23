<?php

use App\Enums\SellerStatus;
use App\Models\Admin;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    Artisan::call('permissions:sync');
    session()->flush();
});

function getSnapshotFromHtml(string $html): string
{
    if (preg_match('/wire:snapshot="([^"]+)"/', $html, $matches)) {
        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    }

    throw new RuntimeException('Livewire snapshot not found in rendered HTML.');
}

function sendLivewireUpdate($test, string $snapshot, array $calls = [], string $referer = '')
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_LIVEWIRE' => 'true',
        'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
    ];

    if ($referer !== '') {
        $server['HTTP_REFERER'] = $referer;
    }

    return $test->call(
        'POST',
        Livewire::getUpdateUri(),
        parameters: [],
        cookies: [],
        files: [],
        server: $server,
        content: json_encode([
            '_token' => csrf_token(),
            'components' => [
                [
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => $calls,
                ],
            ],
        ])
    );
}

// 1. DEACTIVATED ADMIN
test('Livewire update request is rejected for deactivated admin', function () {
    $admin = Admin::factory()->superAdmin()->create(['is_active' => true]);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.staff'));
    $response->assertOk();
    $snapshot = getSnapshotFromHtml($response->getContent());

    // Deactivate admin
    $admin->forceFill(['is_active' => false])->save();

    // Call action on already mounted component via Livewire update
    $updateResponse = sendLivewireUpdate(
        $this->actingAs($admin, 'admin'),
        $snapshot,
        [['path' => '', 'method' => 'openInviteModal', 'params' => []]],
        route('admin.staff')
    );

    $updateResponse->assertRedirect(route('admin.login'));
    expect(Auth::guard('admin')->check())->toBeFalse();
});

// 2. ADMIN WITH STALE PASSWORD HASH
test('Livewire update request is rejected for admin with stale password hash', function () {
    $admin = Admin::factory()->superAdmin()->create([
        'is_active' => true,
        'password' => Hash::make('InitialAdminPass123!@#'),
    ]);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.staff'));
    $response->assertOk();
    $snapshot = getSnapshotFromHtml($response->getContent());

    $oldHash = Auth::guard('admin')->hashPasswordForCookie($admin->getAuthPassword());
    $session = [
        'password_hash_admin' => $oldHash,
        'password_hash_admin_id' => $admin->id,
    ];

    // Admin password changed in DB
    $admin->forceFill(['password' => Hash::make('NewAdminPass456!@#')])->save();

    $updateResponse = sendLivewireUpdate(
        $this->withSession($session)->actingAs($admin, 'admin'),
        $snapshot,
        [['path' => '', 'method' => 'openInviteModal', 'params' => []]],
        route('admin.staff')
    );

    $updateResponse->assertRedirect(route('admin.login'));
    expect(Auth::guard('admin')->check())->toBeFalse();
});

// 3. ADMIN WITH MUST_CHANGE_PASSWORD
test('Livewire update request is rejected for admin with must_change_password', function () {
    $admin = Admin::factory()->superAdmin()->create([
        'is_active' => true,
        'must_change_password' => false,
    ]);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.staff'));
    $response->assertOk();
    $snapshot = getSnapshotFromHtml($response->getContent());

    // Flag admin as must_change_password
    $admin->forceFill(['must_change_password' => true])->save();

    $updateResponse = sendLivewireUpdate(
        $this->actingAs($admin, 'admin'),
        $snapshot,
        [['path' => '', 'method' => 'openInviteModal', 'params' => []]],
        route('admin.staff')
    );

    $updateResponse->assertRedirect(route('admin.password.force-change'));
});

// 4. DEACTIVATED CUSTOMER
test('Livewire update request is rejected for deactivated customer', function () {
    $customer = User::factory()->create([
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($customer, 'web')->get(route('profile.edit'));
    $response->assertOk();
    $snapshot = getSnapshotFromHtml($response->getContent());

    // Deactivate customer
    $customer->forceFill(['is_active' => false])->save();

    $updateResponse = sendLivewireUpdate(
        $this->actingAs($customer, 'web'),
        $snapshot,
        [],
        route('profile.edit')
    );

    $updateResponse->assertRedirect(route('login'));
    expect(Auth::guard('web')->check())->toBeFalse();
});

// 5. CUSTOMER WITH MUST_CHANGE_PASSWORD
test('Livewire update request is rejected for customer with must_change_password', function () {
    $customer = User::factory()->create([
        'is_active' => true,
        'must_change_password' => false,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($customer, 'web')->get(route('profile.edit'));
    $response->assertOk();
    $snapshot = getSnapshotFromHtml($response->getContent());

    // Flag customer as must_change_password
    $customer->forceFill(['must_change_password' => true])->save();

    $updateResponse = sendLivewireUpdate(
        $this->actingAs($customer, 'web'),
        $snapshot,
        [],
        route('profile.edit')
    );

    $updateResponse->assertRedirect(route('password.force-change'));
});

// 6. SUSPENDED SELLER
test('Livewire update request is rejected for suspended seller', function () {
    $seller = User::factory()->create([
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $profile = SellerProfile::factory()->approved()->create([
        'user_id' => $seller->id,
    ]);

    $response = $this->actingAs($seller, 'web')->get(route('seller.products.index'));
    $response->assertOk();
    $snapshot = getSnapshotFromHtml($response->getContent());

    // Suspend seller
    $profile->update(['status' => SellerStatus::Suspended]);
    $seller->unsetRelation('sellerProfile');

    $updateResponse = sendLivewireUpdate(
        $this->actingAs($seller, 'web'),
        $snapshot,
        [],
        route('seller.products.index')
    );

    $updateResponse->assertRedirect(route('seller.apply'));
});
