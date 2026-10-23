<?php

use App\Enums\SellerStatus;
use App\Exceptions\InvalidSellerStatusTransitionException;
use App\Mail\SellerApplicationApproved;
use App\Mail\SellerApplicationRejected;
use App\Models\Admin;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

test('admin can view seller applications list and filter by status', function () {
    $admin = Admin::factory()->superAdmin()->create();

    $pending = SellerProfile::factory()->pending()->create(['store_name' => 'Pending Toys']);
    $approved = SellerProfile::factory()->approved()->create(['store_name' => 'Approved Toys']);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.sellers.index'));

    $response->assertOk()
        ->assertSee('Pending Toys')
        ->assertSee('Approved Toys');

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.sellers-index')
        ->set('statusFilter', 'pending')
        ->assertSee('Pending Toys')
        ->assertDontSee('Approved Toys');
});

test('admin sellers index shows bank details changed badge', function () {
    $admin = Admin::factory()->superAdmin()->create();

    $seller = SellerProfile::factory()->approved()->create([
        'store_name' => 'Bank Alert Store',
        'bank_updated_at' => now(),
    ]);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.sellers.index'));

    $response->assertOk()
        ->assertSee('Bank details changed');
});

test('admin can approve seller application and email is queued', function () {
    Mail::fake();
    $admin = Admin::factory()->superAdmin()->create();
    $user = User::factory()->create(['email' => 'applicant@example.com']);
    $profile = SellerProfile::factory()->pending()->create([
        'user_id' => $user->id,
        'store_name' => 'New Awesome Store',
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.sellers-show', ['sellerProfile' => $profile])
        ->call('approveApplication')
        ->assertHasNoErrors();

    $fresh = $profile->fresh();
    expect($fresh->status)->toBe(SellerStatus::Approved)
        ->and($fresh->approved_at)->not->toBeNull()
        ->and($fresh->reviewed_at)->not->toBeNull()
        ->and($fresh->reviewed_by_admin_id)->toBe($admin->id);

    Mail::assertQueued(SellerApplicationApproved::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});

test('admin can reject seller application with reason and email is queued', function () {
    Mail::fake();
    $admin = Admin::factory()->superAdmin()->create();
    $user = User::factory()->create(['email' => 'applicant2@example.com']);
    $profile = SellerProfile::factory()->pending()->create([
        'user_id' => $user->id,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.sellers-show', ['sellerProfile' => $profile])
        ->set('rejection_reason', 'Address proof document is expired.')
        ->call('rejectApplication')
        ->assertHasNoErrors();

    $fresh = $profile->fresh();
    expect($fresh->status)->toBe(SellerStatus::Rejected)
        ->and($fresh->rejection_reason)->toBe('Address proof document is expired.')
        ->and($fresh->reviewed_at)->not->toBeNull()
        ->and($fresh->reviewed_by_admin_id)->toBe($admin->id);

    Mail::assertQueued(SellerApplicationRejected::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email) && $mail->reason === 'Address proof document is expired.';
    });
});

test('admin can suspend and reactivate seller', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $profile = SellerProfile::factory()->approved()->create();

    // Suspend
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.sellers-show', ['sellerProfile' => $profile])
        ->call('suspendSeller')
        ->assertHasNoErrors();

    expect($profile->fresh()->status)->toBe(SellerStatus::Suspended);

    // Reactivate
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.sellers-show', ['sellerProfile' => $profile->fresh()])
        ->call('reactivateSeller')
        ->assertHasNoErrors();

    expect($profile->fresh()->status)->toBe(SellerStatus::Approved);
});

test('status transitions are strictly guarded and invalid transitions throw', function () {
    $admin = Admin::factory()->superAdmin()->create();

    // Cannot approve a draft
    $draft = SellerProfile::factory()->create(['status' => SellerStatus::Draft]);
    expect(fn () => $draft->approve($admin))->toThrow(InvalidSellerStatusTransitionException::class);

    // Cannot suspend a pending application
    $pending = SellerProfile::factory()->pending()->create();
    expect(fn () => $pending->suspend($admin))->toThrow(InvalidSellerStatusTransitionException::class);

    // Cannot reactivate an already approved seller
    $approved = SellerProfile::factory()->approved()->create();
    expect(fn () => $approved->reactivate($admin))->toThrow(InvalidSellerStatusTransitionException::class);

    // Rejection requires non-empty reason
    expect(fn () => $pending->reject($admin, '   '))->toThrow(InvalidSellerStatusTransitionException::class);
});
