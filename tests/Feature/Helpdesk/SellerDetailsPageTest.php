<?php

use App\Enums\SellerStatus;
use App\Models\SellerProfile;
use App\Models\User;
use App\Reviews\Enums\ReviewStatus;
use App\Reviews\Models\ProductReview;
use Livewire\Livewire;

function detailsSeller(array $attributes = []): SellerProfile
{
    return SellerProfile::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Toy Kingdom',
        'store_slug' => 'toy-kingdom-'.uniqid(),
        'legal_name' => 'Toy Kingdom Retail LLP',
        'pickup_address_line1' => '12 Market Street',
        'pickup_city' => 'Jaipur',
        'pickup_state' => 'Rajasthan',
        'pickup_postal_code' => '302001',
        'approved_at' => now()->subYear(),
        ...$attributes,
    ]);
}

test('customers see the seller\'s business details, rating and marketplace customer care', function () {
    $seller = detailsSeller(['gstin' => '08ABCDE1234F1Z5']);
    foreach ([5, 4] as $rating) {
        ProductReview::create([
            'reviewable_type' => 'product',
            'reviewable_id' => 1,
            'user_id' => User::factory()->create()->id,
            'author_name' => 'Buyer',
            'rating' => $rating,
            'comment' => 'Good',
            'status' => ReviewStatus::Approved,
            'seller_id' => $seller->id,
        ]);
    }

    $this->get(route('sellers.show', ['slug' => $seller->store_slug]))
        ->assertOk()
        ->assertSee('Toy Kingdom Retail LLP')
        ->assertSee('12 Market Street')
        ->assertSee('Jaipur, Rajasthan 302001')
        ->assertSee('08ABCDE1234F1Z5')
        ->assertSee('4.5')
        ->assertSee('2 ratings')
        ->assertSee(route('support.create'), false)
        // Customers contact the marketplace, never the seller directly
        ->assertDontSee($seller->support_email)
        ->assertDontSee($seller->support_phone);
});

test('pending and rejected sellers have no public page', function () {
    $this->get(route('sellers.show', ['slug' => detailsSeller(['status' => SellerStatus::Pending])->store_slug]))->assertNotFound();
    $this->get(route('sellers.show', ['slug' => 'no-such-store']))->assertNotFound();
});

test('onboarding requires the registered business name', function () {
    $user = User::factory()->create();
    SellerProfile::factory()->create(['user_id' => $user->id, 'current_step' => 3]);

    Livewire::actingAs($user)
        ->test('pages::seller.onboarding')
        ->set('currentStep', 3)
        ->set('pan', 'ABCDE1234F')
        ->set('bank_account_holder', 'John Seller')
        ->set('bank_account_number', '987654321012')
        ->set('bank_account_number_confirmation', '987654321012')
        ->set('ifsc', 'HDFC0001234')
        ->call('saveStep3')
        ->assertHasErrors(['legal_name']);
});

test('existing sellers confirm their legal name and declaration from the dashboard', function () {
    $seller = detailsSeller(['legal_name' => null, 'declaration_accepted_at' => null]);
    $user = $seller->user;

    $this->actingAs($user)->get(route('seller.dashboard'))->assertSee('Confirm your business details');

    Livewire::actingAs($user)
        ->test('pages::seller.dashboard')
        ->call('acceptDeclaration')
        ->assertHasErrors(['legalName', 'declarationAccepted'])
        ->set('legalName', 'Toy Kingdom Retail LLP')
        ->set('declarationAccepted', true)
        ->call('acceptDeclaration')
        ->assertHasNoErrors();

    $seller->refresh();
    expect($seller->legal_name)->toBe('Toy Kingdom Retail LLP')
        ->and($seller->declaration_accepted_at)->not->toBeNull();

    $this->actingAs($user)->get(route('seller.dashboard'))->assertDontSee('Confirm your business details');
});

test('product page links "Sold by" to the seller details page', function () {
    $product = makeSearchableProduct();
    $variant = $product->variants()->firstOrFail();
    $seller = SellerProfile::query()->where('status', SellerStatus::Approved)->firstOrFail();

    $this->get(route('product.show', ['slug' => $product->slug, 'upid' => $variant->upid]))
        ->assertOk()
        ->assertSee(route('sellers.show', ['slug' => $seller->store_slug]), false);
});
