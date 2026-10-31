<?php

use App\Catalog\Support\Countries;
use App\Enums\NetQuantityUnit;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Models\Admin;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use Livewire\Livewire;

/**
 * One variant with an offer, so variant checks pass and only declarations can block.
 */
function withSellableVariant(Product $product): Product
{
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'variant_attributes' => []]);
    Listing::factory()->create(['product_variant_id' => $variant->id]);

    return $product;
}

function declarationsSeller(): User
{
    $user = User::factory()->create();
    SellerProfile::factory()->create(['user_id' => $user->id, 'status' => SellerStatus::Approved]);

    return $user;
}

test('net quantity is formatted with its unit symbol', function () {
    expect(NetQuantityUnit::format('1.000', NetQuantityUnit::Count))->toBe('1 N')
        ->and(NetQuantityUnit::format('500', NetQuantityUnit::Gram))->toBe('500 g')
        ->and(NetQuantityUnit::format('1.250', NetQuantityUnit::Kilogram))->toBe('1.25 kg')
        ->and(NetQuantityUnit::format('2', NetQuantityUnit::Pair))->toBe('2 pairs')
        ->and(NetQuantityUnit::format('1', NetQuantityUnit::Pair))->toBe('1 pair')
        ->and(NetQuantityUnit::format('3', NetQuantityUnit::SquareMetre))->toBe('3 m²')
        ->and(NetQuantityUnit::format(null, NetQuantityUnit::Gram))->toBeNull();
});

test('country list puts India first and knows imports', function () {
    expect(array_key_first(Countries::options()))->toBe('IN')
        ->and(Countries::name('cn'))->toBe('China')
        ->and(Countries::name('XX'))->toBeNull()
        ->and(Countries::isImported('IN'))->toBeFalse()
        ->and(Countries::isImported('CN'))->toBeTrue()
        ->and(Countries::codes())->toContain('US', 'IN', 'JP');
});

test('missing declarations count pending changes', function () {
    $product = Product::factory()->make(['generic_name' => null, 'net_quantity' => null, 'net_quantity_unit' => null]);
    expect($product->missingDeclarations())->toBe(['generic name', 'net quantity']);

    $product->pending_changes = ['generic_name' => 'Board game', 'net_quantity' => '1', 'net_quantity_unit' => 'count'];
    expect($product->missingDeclarations())->toBe([]);

    $live = Product::factory()->make(['pending_changes' => ['generic_name' => '']]);
    expect($live->missingDeclarations())->toBe(['generic name']);
});

test('wizard step 3 requires generic name and a valid net quantity', function () {
    Livewire::actingAs(declarationsSeller())
        ->test('pages::seller.products-create')
        ->call('startNewProduct')
        ->set('title', 'Superhero Action Figure')
        ->set('description', 'Poseable six inch action figure with accessories.')
        ->set('net_quantity', '-1')
        ->call('saveStep3')
        ->assertHasErrors(['generic_name' => 'required', 'net_quantity' => 'gt'])
        ->assertSet('currentStep', 1);
});

test('wizard saves declarations on the draft and reloads them', function () {
    $user = declarationsSeller();
    $product = Product::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::seller.products-create')
        ->call('startNewProduct')
        ->set('vertical_id', $product->vertical_id)
        ->set('brand_id', $product->brand_id)
        ->set('title', 'Superhero Action Figure')
        ->set('description', 'Poseable six inch action figure with accessories.')
        ->set('generic_name', 'Action figure')
        ->set('net_quantity', '2')
        ->set('net_quantity_unit', 'count')
        ->call('saveStep3')
        ->assertHasNoErrors()
        ->assertSet('currentStep', 4);

    $draft = Product::where('qc_status', ProductQcStatus::Draft)->latest('id')->firstOrFail();
    expect($draft->generic_name)->toBe('Action figure')
        ->and($draft->netQuantityLabel())->toBe('2 N');

    Livewire::actingAs($user)
        ->test('pages::seller.products-create')
        ->assertSet('draftProductId', $draft->id)
        ->assertSet('generic_name', 'Action figure')
        ->assertSet('net_quantity', '2')
        ->assertSet('net_quantity_unit', 'count');
});

test('draft without declarations cannot be submitted to QC', function () {
    $user = declarationsSeller();
    $draft = withSellableVariant(Product::factory()->draft()->create([
        'created_by' => $user->sellerProfile->id,
        'title' => 'Wooden Puzzle Set',
        'description' => 'Ten wooden jigsaw puzzles for toddlers.',
        'generic_name' => null,
        'net_quantity' => null,
        'net_quantity_unit' => null,
    ]));

    Livewire::actingAs($user)
        ->test('pages::seller.products-create')
        ->assertSet('draftProductId', $draft->id)
        ->call('submitToQc')
        ->assertSet('currentStep', 3)
        ->assertSee('Add the product&#039;s generic name and net quantity before submitting.', false)
        ->assertNoRedirect();

    expect($draft->fresh()->qc_status)->toBe(ProductQcStatus::Draft);
});

test('admin cannot approve a product missing declarations', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $product = withSellableVariant(Product::factory()->qcPending()->create(['generic_name' => null]));

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.products')
        ->call('openReviewModal', $product->id)
        ->assertSee('Package Declarations')
        ->assertSee('Missing generic name')
        ->assertSee('Approve Blocked')
        ->call('approveProduct', $product->id)
        ->assertSee('has not given the product&#039;s generic name', false);

    expect($product->fresh()->qc_status)->toBe(ProductQcStatus::QcPending);
});

test('admin QC shows pending declarations and approval applies them', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $product = withSellableVariant(Product::factory()->qcPending()->create([
        'generic_name' => null,
        'net_quantity' => null,
        'net_quantity_unit' => null,
        'pending_changes' => ['generic_name' => 'Board game', 'net_quantity' => '1', 'net_quantity_unit' => 'count'],
    ]));

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.catalog.products')
        ->call('openReviewModal', $product->id)
        ->assertSee('Pending: Board game')
        ->assertSee('Pending: 1 N')
        ->assertDontSee('Approve Blocked');

    $product->approve($admin);

    $product->refresh();
    expect($product->generic_name)->toBe('Board game')
        ->and($product->net_quantity_unit)->toBe(NetQuantityUnit::Count)
        ->and($product->netQuantityLabel())->toBe('1 N')
        ->and($product->pending_changes)->toBeNull();
});
