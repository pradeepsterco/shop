<?php

use App\Models\Admin;
use App\Models\SellerDocument;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('kyc documents cannot be viewed by guests', function () {
    $document = SellerDocument::factory()->create();

    $response = $this->get(route('seller.documents.show', $document));

    $response->assertRedirect(route('login'));
});

test('kyc documents cannot be viewed by other customers', function () {
    $owner = User::factory()->create();
    $profile = SellerProfile::factory()->create(['user_id' => $owner->id]);
    $document = SellerDocument::factory()->create(['seller_profile_id' => $profile->id]);

    $otherUser = User::factory()->create();

    $response = $this->actingAs($otherUser)->get(route('seller.documents.show', $document));

    $response->assertForbidden();
});

test('document owner can download document', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $profile = SellerProfile::factory()->create(['user_id' => $owner->id]);

    $path = 'seller-documents/owner-id-proof.pdf';
    Storage::disk('local')->put($path, 'sample file content');

    $document = SellerDocument::factory()->create([
        'seller_profile_id' => $profile->id,
        'path' => $path,
        'original_name' => 'owner-id-proof.pdf',
    ]);

    $response = $this->actingAs($owner)->get(route('seller.documents.show', $document));

    $response->assertOk();
});

test('admin can download document via admin route', function () {
    Storage::fake('local');
    $admin = Admin::factory()->superAdmin()->create();
    $owner = User::factory()->create();
    $profile = SellerProfile::factory()->create(['user_id' => $owner->id]);

    $path = 'seller-documents/admin-check-doc.pdf';
    Storage::disk('local')->put($path, 'admin review content');

    $document = SellerDocument::factory()->create([
        'seller_profile_id' => $profile->id,
        'path' => $path,
        'original_name' => 'admin-check-doc.pdf',
    ]);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.sellers.documents.show', $document));

    $response->assertOk();
});

test('deleting a user account permanently deletes logo banner and kyc files', function () {
    Storage::fake('public');
    Storage::fake('local');

    $user = User::factory()->create();

    $logoPath = 'seller-logos/test-logo.png';
    $bannerPath = 'seller-banners/test-banner.jpg';
    $docPath = 'seller-documents/test-kyc.pdf';

    Storage::disk('public')->put($logoPath, 'logo-content');
    Storage::disk('public')->put($bannerPath, 'banner-content');
    Storage::disk('local')->put($docPath, 'kyc-content');

    $profile = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'logo_path' => $logoPath,
        'banner_path' => $bannerPath,
    ]);

    $doc = SellerDocument::factory()->create([
        'seller_profile_id' => $profile->id,
        'path' => $docPath,
    ]);

    Storage::disk('public')->assertExists($logoPath);
    Storage::disk('public')->assertExists($bannerPath);
    Storage::disk('local')->assertExists($docPath);

    // Delete user account
    $user->delete();

    // All files should be deleted from disks
    Storage::disk('public')->assertMissing($logoPath);
    Storage::disk('public')->assertMissing($bannerPath);
    Storage::disk('local')->assertMissing($docPath);
});
