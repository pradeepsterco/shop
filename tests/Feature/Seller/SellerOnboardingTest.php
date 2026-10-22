<?php

use App\Enums\SellerStatus;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('authenticated verified customer can view onboarding wizard', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('seller.apply'));

    $response->assertOk()
        ->assertSee('Seller Registration')
        ->assertSee('Step 1: Store Details');
});

test('wizard step 1 validates and saves draft profile', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->set('store_name', 'Toy Universe')
        ->set('store_slug', 'toy-universe')
        ->set('business_type', 'individual')
        ->call('saveStep1')
        ->assertHasNoErrors()
        ->assertSet('currentStep', 2);

    $profile = SellerProfile::where('user_id', $user->id)->first();
    expect($profile)->not->toBeNull()
        ->and($profile->store_name)->toBe('Toy Universe')
        ->and($profile->store_slug)->toBe('toy-universe')
        ->and($profile->current_step)->toBe(2)
        ->and($profile->status)->toBe(SellerStatus::Draft);
});

test('wizard validates format of pan and ifsc on step 3', function () {
    $user = User::factory()->create();
    $profile = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'current_step' => 3,
    ]);

    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->set('currentStep', 3)
        ->set('pan', 'INVALID123')
        ->set('bank_account_holder', 'John Seller')
        ->set('bank_account_number', '1234567890')
        ->set('bank_account_number_confirmation', '1234567890')
        ->set('ifsc', 'INVALID_IFSC')
        ->call('saveStep3')
        ->assertHasErrors(['pan', 'ifsc']);
});

test('wizard step 3 encrypts sensitive fields in database', function () {
    $user = User::factory()->create();
    $profile = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'current_step' => 3,
    ]);

    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->set('currentStep', 3)
        ->set('pan', 'ABCDE1234F')
        ->set('gstin', '27ABCDE1234F1Z5')
        ->set('bank_account_holder', 'John Beneficiary')
        ->set('bank_account_number', '987654321012')
        ->set('bank_account_number_confirmation', '987654321012')
        ->set('ifsc', 'HDFC0001234')
        ->call('saveStep3')
        ->assertHasNoErrors()
        ->assertSet('currentStep', 4);

    // Verify raw database record is encrypted and doesn't contain raw plaintext
    $raw = DB::table('seller_profiles')->where('id', $profile->id)->first();
    expect($raw->pan)->not->toBe('ABCDE1234F')
        ->and($raw->bank_account_number)->not->toBe('987654321012');

    // Verify model decrypts automatically
    $fresh = $profile->fresh();
    expect($fresh->pan)->toBe('ABCDE1234F')
        ->and($fresh->bank_account_number)->toBe('987654321012');
});

test('wizard step 4 uploads documents to private disk and replacing deletes old file', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $profile = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'current_step' => 4,
    ]);

    $idProof1 = UploadedFile::fake()->create('pan_card_v1.pdf', 500, 'application/pdf');
    $addressProof1 = UploadedFile::fake()->create('address_bill_v1.jpg', 500, 'image/jpeg');

    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->set('currentStep', 4)
        ->set('id_proof', $idProof1)
        ->set('address_proof', $addressProof1)
        ->call('saveStep4')
        ->assertHasNoErrors()
        ->assertSet('currentStep', 5);

    $doc1 = $profile->documents()->where('type', 'id_proof')->first();
    expect($doc1)->not->toBeNull();
    $oldPath = $doc1->path;
    Storage::disk('local')->assertExists($oldPath);

    // Now replace the id_proof file
    $idProof2 = UploadedFile::fake()->create('pan_card_v2.pdf', 600, 'application/pdf');

    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->set('currentStep', 4)
        ->set('id_proof', $idProof2)
        ->call('saveStep4')
        ->assertHasNoErrors();

    // Old file should be deleted from storage
    Storage::disk('local')->assertMissing($oldPath);

    $doc2 = $profile->fresh()->documents()->where('type', 'id_proof')->first();
    expect($doc2->original_name)->toBe('pan_card_v2.pdf');
    Storage::disk('local')->assertExists($doc2->path);
});

test('submitting wizard requires terms and sets status to pending', function () {
    $user = User::factory()->create();
    $profile = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Draft,
        'current_step' => 5,
    ]);

    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->set('currentStep', 5)
        ->set('terms_accepted', false)
        ->call('submitApplication')
        ->assertHasErrors(['terms_accepted']);

    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->set('currentStep', 5)
        ->set('terms_accepted', true)
        ->call('submitApplication')
        ->assertHasNoErrors();

    $fresh = $profile->fresh();
    expect($fresh->status)->toBe(SellerStatus::Pending)
        ->and($fresh->submitted_at)->not->toBeNull();
});

test('wizard resumes at saved current_step', function () {
    $user = User::factory()->create();
    SellerProfile::factory()->create([
        'user_id' => $user->id,
        'current_step' => 3,
        'status' => SellerStatus::Draft,
    ]);

    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->assertSet('currentStep', 3);
});

test('rejected seller can view rejection reason and resubmit', function () {
    $user = User::factory()->create();
    $profile = SellerProfile::factory()->rejected('Documents are not legible')->create([
        'user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->assertSee('Previous Application Rejected')
        ->assertSee('Documents are not legible')
        ->set('currentStep', 5)
        ->set('terms_accepted', true)
        ->call('submitApplication')
        ->assertHasNoErrors();

    expect($profile->fresh()->status)->toBe(SellerStatus::Pending);
});

test('store slug is dynamically generated from store name', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->set('store_name', 'Origin Mart')
        ->assertSet('store_slug', 'origin-mart');
});

test('store slug updates dynamically when store name is modified or replaced', function () {
    $user = User::factory()->create();

    // User first types "test", then renames to "Origin Mart"
    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->set('store_name', 'test')
        ->assertSet('store_slug', 'test')
        ->set('store_name', 'Origin Mart')
        ->assertSet('store_slug', 'origin-mart');
});

test('store slug auto-increments if identical slug already exists', function () {
    $existing = User::factory()->create();
    SellerProfile::factory()->create([
        'user_id' => $existing->id,
        'store_slug' => 'origin-mart',
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->set('store_name', 'Origin Mart')
        ->assertSet('store_slug', 'origin-mart-1');
});

test('manually customized store slug is preserved when store name changes', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->set('store_name', 'Origin Mart')
        ->assertSet('store_slug', 'origin-mart')
        ->set('store_slug', 'custom-outlet')
        ->assertSet('is_slug_customized', true)
        ->set('store_name', 'Super Origin Mart')
        ->assertSet('store_slug', 'custom-outlet')
        ->call('generateSlug')
        ->assertSet('store_slug', 'super-origin-mart')
        ->assertSet('is_slug_customized', false);
});
