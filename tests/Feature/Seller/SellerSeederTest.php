<?php

use App\Enums\SellerStatus;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\SellerSeeder;

test('seller seeder creates demo sellers across different statuses', function () {
    $this->artisan('db:seed', ['--class' => SellerSeeder::class]);

    // 1. Approved Demo Seller
    $approvedUser = User::where('email', 'seller@example.com')->first();
    expect($approvedUser)->not->toBeNull()
        ->and($approvedUser->isSeller())->toBeTrue();

    $approvedProfile = $approvedUser->sellerProfile;
    expect($approvedProfile)->not->toBeNull()
        ->and($approvedProfile->status)->toBe(SellerStatus::Approved)
        ->and($approvedProfile->store_slug)->toBe('khilauna-toys')
        ->and($approvedProfile->documents)->toHaveCount(2);

    // 2. Pending Seller
    $pendingUser = User::where('email', 'pending.seller@example.com')->first();
    expect($pendingUser)->not->toBeNull()
        ->and($pendingUser->isSeller())->toBeFalse();

    $pendingProfile = $pendingUser->sellerProfile;
    expect($pendingProfile)->not->toBeNull()
        ->and($pendingProfile->status)->toBe(SellerStatus::Pending);

    // 3. Rejected Seller
    $rejectedUser = User::where('email', 'rejected.seller@example.com')->first();
    expect($rejectedUser)->not->toBeNull()
        ->and($rejectedUser->sellerProfile->status)->toBe(SellerStatus::Rejected)
        ->and($rejectedUser->sellerProfile->rejection_reason)->not->toBeNull();

    // 4. Bank Details Updated Seller
    $bankUser = User::where('email', 'bankchanged.seller@example.com')->first();
    expect($bankUser)->not->toBeNull()
        ->and($bankUser->sellerProfile->status)->toBe(SellerStatus::Approved)
        ->and($bankUser->sellerProfile->bank_updated_at)->not->toBeNull();
});

test('seller seeder is idempotent and can be run multiple times', function () {
    $this->artisan('db:seed', ['--class' => SellerSeeder::class]);
    $this->artisan('db:seed', ['--class' => SellerSeeder::class]);

    expect(User::where('email', 'seller@example.com')->count())->toBe(1)
        ->and(SellerProfile::where('store_slug', 'khilauna-toys')->count())->toBe(1);
});
