<?php

use App\Enums\SellerStatus;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('non sellers visiting seller dashboard are redirected to onboarding', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('seller.dashboard'));

    $response->assertRedirect(route('seller.apply'));
});

test('pending or suspended sellers visiting seller dashboard are redirected to onboarding', function () {
    $userPending = User::factory()->create();
    SellerProfile::factory()->pending()->create(['user_id' => $userPending->id]);

    $this->actingAs($userPending)->get(route('seller.dashboard'))
        ->assertRedirect(route('seller.apply'));

    $userSuspended = User::factory()->create();
    SellerProfile::factory()->suspended()->create(['user_id' => $userSuspended->id]);

    $this->actingAs($userSuspended)->get(route('seller.dashboard'))
        ->assertRedirect(route('seller.apply'));
});

test('approved seller can view seller dashboard overview', function () {
    $user = User::factory()->create();
    $profile = SellerProfile::factory()->approved()->create([
        'user_id' => $user->id,
        'store_name' => 'Mega Toy Mart',
    ]);

    $response = $this->actingAs($user)->get(route('seller.dashboard'));

    $response->assertOk()
        ->assertSee('Mega Toy Mart')
        ->assertSee('Store Active and Approved')
        ->assertSee('Switch to customer');
});

test('approved seller can edit store profile but pan and slug are read only', function () {
    $user = User::factory()->create();
    $profile = SellerProfile::factory()->approved()->create([
        'user_id' => $user->id,
        'store_name' => 'Original Toys',
        'store_slug' => 'original-slug',
        'pan' => 'ABCDE1234F',
    ]);

    Livewire::actingAs($user)
        ->test('pages::seller.profile')
        ->set('store_name', 'Updated Toys')
        ->set('store_slug', 'attempted-new-slug')
        ->set('pan', 'NEWPAN9999Z')
        ->call('updateStoreProfile')
        ->assertHasNoErrors();

    $fresh = $profile->fresh();
    expect($fresh->store_name)->toBe('Updated Toys')
        ->and($fresh->store_slug)->toBe('original-slug') // Unchanged!
        ->and($fresh->pan)->toBe('ABCDE1234F'); // Unchanged!
});

test('editing bank details requires password confirmation sets bank_updated_at and seller stays approved', function () {
    $user = User::factory()->create([
        'password' => Hash::make('secret-password-123'),
    ]);
    $profile = SellerProfile::factory()->approved()->create([
        'user_id' => $user->id,
        'bank_account_number' => '1111111111',
        'ifsc' => 'HDFC0001111',
        'bank_updated_at' => null,
    ]);

    // Wrong password fails
    Livewire::actingAs($user)
        ->test('pages::seller.settings')
        ->set('bank_account_holder', 'Updated Holder')
        ->set('bank_account_number', '2222222222')
        ->set('bank_account_number_confirmation', '2222222222')
        ->set('ifsc', 'SBIN0002222')
        ->set('current_password', 'wrong-password')
        ->call('updateBankDetails')
        ->assertHasErrors(['current_password']);

    expect($profile->fresh()->bank_updated_at)->toBeNull();

    // Correct password succeeds
    Livewire::actingAs($user)
        ->test('pages::seller.settings')
        ->set('bank_account_holder', 'Updated Holder')
        ->set('bank_account_number', '2222222222')
        ->set('bank_account_number_confirmation', '2222222222')
        ->set('ifsc', 'SBIN0002222')
        ->set('current_password', 'secret-password-123')
        ->call('updateBankDetails')
        ->assertHasNoErrors();

    $fresh = $profile->fresh();
    expect($fresh->bank_account_number)->toBe('2222222222')
        ->and($fresh->ifsc)->toBe('SBIN0002222')
        ->and($fresh->bank_updated_at)->not->toBeNull()
        ->and($fresh->status)->toBe(SellerStatus::Approved); // Seller stays approved!
});

test('dashboard navigation displays switch links according to role', function () {
    $customer = User::factory()->create();

    $response = $this->actingAs($customer)->get(route('dashboard'));
    $response->assertOk()
        ->assertSee('Become a seller');

    $seller = User::factory()->create();
    SellerProfile::factory()->approved()->create(['user_id' => $seller->id]);

    $response2 = $this->actingAs($seller)->get(route('dashboard'));
    $response2->assertOk()
        ->assertSee('Switch to seller');
});
