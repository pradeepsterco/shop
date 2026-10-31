<?php

use App\Enums\SellerStatus;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use Livewire\Livewire;

function draftSeller(): User
{
    $user = User::factory()->create();
    SellerProfile::factory()->create(['user_id' => $user->id, 'status' => SellerStatus::Approved]);

    return $user;
}

function sellerDraft(User $user, array $attributes = []): Product
{
    return Product::factory()->draft()->create([
        'created_by' => $user->sellerProfile->id,
        'title' => 'Wooden Train Set',
        'description' => 'Twenty piece wooden train set with tracks.',
        ...$attributes,
    ]);
}

test('continue opens the chosen draft, not just the newest one', function () {
    $user = draftSeller();
    $older = sellerDraft($user, ['title' => 'Older Puzzle Draft', 'generic_name' => null]);
    sellerDraft($user, ['title' => 'Newest Draft Product']);

    Livewire::withQueryParams(['draft' => $older->id])
        ->actingAs($user)
        ->test('pages::seller.products-create')
        ->assertSet('draftProductId', $older->id)
        ->assertSet('title', 'Older Puzzle Draft')
        ->assertSet('isCreatingNew', true)
        ->assertSet('currentStep', 3);
});

test('a resumed draft opens at its first unfinished step', function () {
    $user = draftSeller();

    $untitled = sellerDraft($user, ['title' => 'Untitled Draft Product', 'description' => '']);
    Livewire::withQueryParams(['draft' => $untitled->id])->actingAs($user)
        ->test('pages::seller.products-create')
        ->assertSet('currentStep', 3)
        ->assertSet('title', '');

    $detailsDone = sellerDraft($user);
    Livewire::withQueryParams(['draft' => $detailsDone->id])->actingAs($user)
        ->test('pages::seller.products-create')
        ->assertSet('currentStep', 4);

    $ready = sellerDraft($user);
    $variant = ProductVariant::factory()->create(['product_id' => $ready->id, 'variant_attributes' => []]);
    Listing::factory()->create(['product_variant_id' => $variant->id, 'seller_profile_id' => $user->sellerProfile->id]);
    ProductImage::create(['product_id' => $ready->id, 'path' => 'product-images/train.jpg', 'sort_order' => 0, 'is_primary' => true]);
    Livewire::withQueryParams(['draft' => $ready->id])->actingAs($user)
        ->test('pages::seller.products-create')
        ->assertSet('currentStep', 5);
});

test('another seller\'s draft cannot be continued', function () {
    $owner = draftSeller();
    $draft = sellerDraft($owner);

    Livewire::withQueryParams(['draft' => $draft->id])
        ->actingAs(draftSeller())
        ->test('pages::seller.products-create')
        ->assertNotFound();
});

test('continuing a product that already left draft goes to its edit page', function () {
    $user = draftSeller();
    $submitted = Product::factory()->qcPending()->create(['created_by' => $user->sellerProfile->id]);

    Livewire::withQueryParams(['draft' => $submitted->id])
        ->actingAs($user)
        ->test('pages::seller.products-create')
        ->assertRedirect(route('seller.products.edit', $submitted));
});

test('my products offers continue on drafts', function () {
    $user = draftSeller();
    $draft = sellerDraft($user);

    Livewire::actingAs($user)
        ->test('pages::seller.products-index')
        ->set('status_filter', 'draft')
        ->assertSee(route('seller.products.create', ['draft' => $draft->id]), false)
        ->call('openDetailModal', $draft->id)
        ->assertSee('Continue Draft');
});

test('edit page sends the creator\'s draft to the wizard and hides others\' drafts', function () {
    $user = draftSeller();
    $draft = sellerDraft($user);

    Livewire::actingAs($user)
        ->test('pages::seller.products-edit', ['product' => $draft])
        ->assertRedirect(route('seller.products.create', ['draft' => $draft->id]));

    Livewire::actingAs(draftSeller())
        ->test('pages::seller.products-edit', ['product' => $draft])
        ->assertNotFound();

    expect($draft->fresh()->qc_status->value)->toBe('draft');
});
