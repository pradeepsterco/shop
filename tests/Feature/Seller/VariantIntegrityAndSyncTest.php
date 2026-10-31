<?php

use App\Enums\AttributeType;
use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Models\VerticalAttribute;
use App\Services\VariantOfferValidator;
use App\Services\VariantSyncService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->seller = SellerProfile::factory()->create([
        'user_id' => $this->user->id,
        'status' => SellerStatus::Approved,
    ]);

    $this->brand = Brand::create(['name' => 'Acme', 'slug' => 'acme', 'status' => BrandStatus::Approved]);
    $this->category = Category::create(['name' => 'Fashion']);
    $this->vertical = Vertical::create([
        'category_id' => $this->category->id,
        'name' => 'Apparel',
        'hsn_code' => '6109',
        'gst_rate' => 5.0,
    ]);

    $this->sizeAttr = VerticalAttribute::create([
        'vertical_id' => $this->vertical->id,
        'name' => 'Size',
        'code' => 'size',
        'type' => AttributeType::Select,
        'is_variant' => true,
        'options' => [
            ['label' => 'S', 'value' => 's', 'is_active' => true],
            ['label' => 'M', 'value' => 'm', 'is_active' => true],
            ['label' => 'L', 'value' => 'l', 'is_active' => true],
        ],
    ]);
});

test('tick -> untick -> retick -> save creates exactly one variant and listing', function () {
    $component = Livewire::actingAs($this->user)
        ->test('pages::seller.products-create')
        ->call('startNewProduct')
        ->set('vertical_id', $this->vertical->id)
        ->set('brand_id', $this->brand->id)
        ->set('title', 'Summer Graphic T-Shirt')
        ->set('description', 'Comfortable daily wear cotton t-shirt')
        ->set('generic_name', 'T-shirt')
        ->set('net_quantity', '1')
        ->call('saveStep3')
        ->assertSet('currentStep', 4);

    // Tick S
    $component->call('toggleVariantOption', 'size', 's');
    expect($component->get('variantRows'))->toHaveCount(1);

    // Untick S
    $component->call('toggleVariantOption', 'size', 's');
    expect($component->get('variantRows'))->toHaveCount(0);

    // Retick S
    $component->call('toggleVariantOption', 'size', 's');
    expect($component->get('variantRows'))->toHaveCount(1);

    // Set offer details on S
    $rows = $component->get('variantRows');
    $rows[0]['sku'] = 'ACME-SUMMER-S';
    $rows[0]['mrp'] = '999.00';
    $rows[0]['selling_price'] = '699.00';
    $rows[0]['stock'] = '20';
    $component->set('variantRows', $rows);

    $component->call('saveStep4')
        ->assertHasNoErrors()
        ->assertSet('currentStep', 5);

    $draftId = $component->get('draftProductId');
    $product = Product::with('variants.listings')->findOrFail($draftId);

    expect($product->variants)->toHaveCount(1)
        ->and($product->variants[0]->variant_key)->toBe('size:s')
        ->and($product->variants[0]->attribute_snapshot)->not->toBeNull()
        ->and($product->variants[0]->attribute_snapshot['size']['label'])->toBe('S')
        ->and($product->variants[0]->listings)->toHaveCount(1)
        ->and($product->variants[0]->listings[0]->sku)->toBe('ACME-SUMMER-S');
});

test('half-filled variant row is rejected with per-row error and nothing is saved', function () {
    $component = Livewire::actingAs($this->user)
        ->test('pages::seller.products-create')
        ->call('startNewProduct')
        ->set('vertical_id', $this->vertical->id)
        ->set('brand_id', $this->brand->id)
        ->set('title', 'Summer Graphic T-Shirt')
        ->set('description', 'Comfortable daily wear cotton t-shirt')
        ->set('generic_name', 'T-shirt')
        ->set('net_quantity', '1')
        ->call('saveStep3')
        ->assertSet('currentStep', 4);

    // Tick S and M
    $component->call('toggleVariantOption', 'size', 's');
    $component->call('toggleVariantOption', 'size', 'm');

    $rows = $component->get('variantRows');
    // Row 0 is valid
    $rows[0]['sku'] = 'VALID-SKU-S';
    $rows[0]['mrp'] = '800';
    $rows[0]['selling_price'] = '500';
    $rows[0]['stock'] = '10';

    // Row 1 is half-filled (missing price and stock)
    $rows[1]['sku'] = 'HALF-FILLED-M';
    $rows[1]['mrp'] = '800';
    $rows[1]['selling_price'] = '';
    $rows[1]['stock'] = '';
    $component->set('variantRows', $rows);

    $component->call('saveStep4')
        ->assertHasErrors(['variantRows.1.selling_price', 'variantRows.1.stock'])
        ->assertSet('currentStep', 4); // Stay on step 4

    // Verify variants table is not contaminated with half-filled row
    $draftId = $component->get('draftProductId');
    expect(ProductVariant::where('product_id', $draftId)->count())->toBe(0);
});

test('price exceeding MRP and duplicate SKU for same seller are rejected', function () {
    $validator = new VariantOfferValidator;

    $rows = [
        [
            'key' => 'size:s',
            'sku' => 'SKU-001',
            'mrp' => '500',
            'selling_price' => '600', // Error: price > mrp
            'stock' => '10',
        ],
        [
            'key' => 'size:m',
            'sku' => 'SKU-001', // Error: duplicate SKU
            'mrp' => '500',
            'selling_price' => '400',
            'stock' => '10',
        ],
    ];

    $result = $validator->validateRows($rows, $this->seller->id);

    expect($result['errors'])->toHaveKey('0.selling_price')
        ->and($result['errors'])->toHaveKey('1.sku');
});

test('products:flag-invalid-variants command flags invalid products and is idempotent', function () {
    // Seed invalid product: approved with missing price
    $product = Product::create([
        'vertical_id' => $this->vertical->id,
        'brand_id' => $this->brand->id,
        'title' => 'Corrupted Product',
        'description' => 'Test description',
        'created_by' => $this->seller->id,
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'variant_key' => 'size:s',
        'variant_attributes' => ['size' => 's'],
        'attribute_snapshot' => ['size' => ['code' => 'size', 'value' => 's', 'label' => 'S']],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $listing = Listing::create([
        'seller_profile_id' => $this->seller->id,
        'product_variant_id' => $variant->id,
        'sku' => '', // blank SKU
        'mrp' => 50000,
        'selling_price' => 0, // invalid price
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    // Run in dry-run mode
    $this->artisan('products:flag-invalid-variants', ['--dry-run' => true])
        ->expectsOutputToContain('DRY-RUN')
        ->assertSuccessful();

    // Verify dry-run did not mutate DB
    expect($product->fresh()->needs_update)->toBeFalse()
        ->and($listing->fresh()->status)->toBe(ListingStatus::Active);

    // Run without dry-run
    $this->artisan('products:flag-invalid-variants')
        ->assertSuccessful();

    expect($product->fresh()->needs_update)->toBeTrue()
        ->and($product->fresh()->needs_update_reason)->not->toBeEmpty()
        ->and($listing->fresh()->status)->toBe(ListingStatus::Inactive);

    // Run again to verify idempotency
    $this->artisan('products:flag-invalid-variants')
        ->assertSuccessful();

    expect($product->fresh()->needs_update)->toBeTrue();
});

test('orphan variant cleanup soft-deletes unlisted variants without history', function () {
    $product = Product::create([
        'vertical_id' => $this->vertical->id,
        'brand_id' => $this->brand->id,
        'title' => 'Orphan Test Product',
        'description' => 'Test description',
        'created_by' => $this->seller->id,
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $orphan = ProductVariant::create([
        'product_id' => $product->id,
        'variant_key' => 'size:l',
        'variant_attributes' => ['size' => 'l'],
        'qc_status' => VariantQcStatus::Draft,
    ]);

    expect($orphan->listings)->toHaveCount(0);

    $this->artisan('products:flag-invalid-variants')
        ->assertSuccessful();

    expect($orphan->fresh()->trashed())->toBeTrue();
});

test('hiding retiring or deleting an admin option leaves existing variants untouched and unflagged', function () {
    $product = Product::create([
        'vertical_id' => $this->vertical->id,
        'brand_id' => $this->brand->id,
        'title' => 'Decoupled Lifecycle Product',
        'description' => 'Test description',
        'created_by' => $this->seller->id,
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'variant_key' => 'size:m',
        'variant_attributes' => ['size' => 'm'],
        'attribute_snapshot' => [
            'size' => ['code' => 'size', 'value' => 'm', 'label' => 'Medium Snapshot'],
        ],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $listing = Listing::create([
        'seller_profile_id' => $this->seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'VALID-SKU-M',
        'mrp' => 50000,
        'selling_price' => 35000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    // 1. Hide option M
    $options = $this->sizeAttr->options;
    $options[1]['is_active'] = false;
    $this->sizeAttr->update(['options' => $options]);

    expect($product->variantIssues())->toBeEmpty();
    expect($product->fresh()->needs_update)->toBeFalse();

    // 2. Retire option M
    $options[1]['is_retired'] = true;
    $this->sizeAttr->update(['options' => $options]);

    // Variants are decoupled: retiring an option must NOT flag existing products
    expect($product->variantIssues())->toBeEmpty();
    expect($product->fresh()->needs_update)->toBeFalse();

    // 3. Option M completely deleted from admin options
    unset($options[1]);
    $this->sizeAttr->update(['options' => array_values($options)]);

    // Still decoupled: no issue and fallback display label uses snapshot
    expect($product->variantIssues())->toBeEmpty()
        ->and($variant->optionDisplayLabel('size'))->toBe('Medium Snapshot')
        ->and($listing->fresh()->status)->toBe(ListingStatus::Active);
});

test('retired or hidden option cannot be added to a new variant', function () {
    // Retire option L in admin
    $options = $this->sizeAttr->options;
    $options[2]['is_retired'] = true;
    $this->sizeAttr->update(['options' => $options]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::seller.products-create')
        ->call('startNewProduct')
        ->set('vertical_id', $this->vertical->id)
        ->set('brand_id', $this->brand->id)
        ->set('title', 'Testing Retired Option Block')
        ->set('description', 'Description for test')
        ->set('generic_name', 'T-shirt')
        ->set('net_quantity', '1')
        ->call('saveStep3')
        ->assertSet('currentStep', 4);

    // Attempting to select retired option L
    $component->call('toggleVariantOption', 'size', 'l');

    // Option L is not selected and error is shown
    expect($component->instance()->isVariantOptionSelected('size', 'l'))->toBeFalse();
    $component->assertHasErrors(['variantRows']);
});

test('remove unused variant soft-deletes the variant and listing', function () {
    $product = Product::create([
        'vertical_id' => $this->vertical->id,
        'brand_id' => $this->brand->id,
        'title' => 'Unused Variant Product',
        'description' => 'Test description',
        'created_by' => $this->seller->id,
        'qc_status' => ProductQcStatus::Draft,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'variant_key' => 'size:s',
        'variant_attributes' => ['size' => 's'],
        'qc_status' => VariantQcStatus::Draft, // Never QC approved
    ]);

    $listing = Listing::create([
        'seller_profile_id' => $this->seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'UNUSED-S',
        'mrp' => 50000,
        'selling_price' => 35000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    $syncService = new VariantSyncService;
    $result = $syncService->removeVariant($product, $this->seller, $variant->id, $this->user);

    expect($result['outcome'])->toBe('variant_soft_deleted')
        ->and($variant->fresh()->trashed())->toBeTrue()
        ->and($listing->fresh()->trashed())->toBeTrue();
});

test('remove variant with other seller listing archives only this sellers listing', function () {
    $otherUser = User::factory()->create();
    $otherSeller = SellerProfile::factory()->create([
        'user_id' => $otherUser->id,
        'status' => SellerStatus::Approved,
    ]);

    $product = Product::create([
        'vertical_id' => $this->vertical->id,
        'brand_id' => $this->brand->id,
        'title' => 'Shared Variant Product',
        'description' => 'Test description',
        'created_by' => $this->seller->id,
        'qc_status' => ProductQcStatus::Draft,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'variant_key' => 'size:s',
        'variant_attributes' => ['size' => 's'],
        'qc_status' => VariantQcStatus::Draft,
    ]);

    $sellerListing = Listing::create([
        'seller_profile_id' => $this->seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SELLER1-S',
        'mrp' => 50000,
        'selling_price' => 40000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    $otherListing = Listing::create([
        'seller_profile_id' => $otherSeller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SELLER2-S',
        'mrp' => 50000,
        'selling_price' => 42000,
        'stock' => 5,
        'status' => ListingStatus::Active,
    ]);

    $syncService = new VariantSyncService;
    $result = $syncService->removeVariant($product, $this->seller, $variant->id, $this->user);

    expect($result['outcome'])->toBe('other_seller_listing_archived')
        ->and($variant->fresh()->trashed())->toBeFalse()
        ->and($sellerListing->fresh()->status)->toBe(ListingStatus::Inactive)
        ->and($otherListing->fresh()->status)->toBe(ListingStatus::Active);
});

test('remove variant with order history or prior approval archives listing and keeps variant', function () {
    $product = Product::create([
        'vertical_id' => $this->vertical->id,
        'brand_id' => $this->brand->id,
        'title' => 'Approved Variant Product',
        'description' => 'Test description',
        'created_by' => $this->seller->id,
        'qc_status' => ProductQcStatus::Draft,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'variant_key' => 'size:s',
        'variant_attributes' => ['size' => 's'],
        'qc_status' => VariantQcStatus::Approved, // Prior QC approval
    ]);

    $listing = Listing::create([
        'seller_profile_id' => $this->seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'APPROVED-S',
        'mrp' => 50000,
        'selling_price' => 35000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    $syncService = new VariantSyncService;
    $result = $syncService->removeVariant($product, $this->seller, $variant->id, $this->user);

    expect($result['outcome'])->toBe('history_retained_listing_archived')
        ->and($variant->fresh()->trashed())->toBeFalse()
        ->and($listing->fresh()->status)->toBe(ListingStatus::Inactive);
});

test('removal of last valid active variant on approved live product is blocked', function () {
    $product = Product::create([
        'vertical_id' => $this->vertical->id,
        'brand_id' => $this->brand->id,
        'title' => 'Live Approved Product',
        'description' => 'Test description',
        'created_by' => $this->seller->id,
        'qc_status' => ProductQcStatus::Approved, // Approved live product
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'variant_key' => 'size:s',
        'variant_attributes' => ['size' => 's'],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    Listing::create([
        'seller_profile_id' => $this->seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'LAST-ACTIVE-S',
        'mrp' => 50000,
        'selling_price' => 35000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    $syncService = new VariantSyncService;

    expect(fn () => $syncService->removeVariant($product, $this->seller, $variant->id, $this->user))
        ->toThrow(DomainException::class, 'Cannot remove the last active valid variant from an approved live product.');
});

test('remove variant then re-add restores the same variant instead of creating a new one', function () {
    $product = Product::create([
        'vertical_id' => $this->vertical->id,
        'brand_id' => $this->brand->id,
        'title' => 'Restore Test Product',
        'description' => 'Test description',
        'created_by' => $this->seller->id,
        'qc_status' => ProductQcStatus::Draft,
    ]);

    $syncService = new VariantSyncService;

    $rows = [
        [
            'variant_attributes' => ['size' => 's'],
            'sku' => 'RESTORE-S',
            'mrp' => '500',
            'selling_price' => '400',
            'stock' => '10',
        ],
    ];

    // 1. Create variant
    $syncService->sync($product, $this->seller, $rows);
    $originalVariant = ProductVariant::where('product_id', $product->id)->first();
    $originalId = $originalVariant->id;

    // 2. Remove it
    $result = $syncService->removeVariant($product, $this->seller, $originalId, $this->user);
    expect($result['outcome'])->toBe('variant_soft_deleted')
        ->and($originalVariant->fresh()->trashed())->toBeTrue();

    // 3. Re-add the same variant
    $syncService->sync($product, $this->seller, $rows);

    $restoredVariant = ProductVariant::where('product_id', $product->id)->first();
    expect($restoredVariant->id)->toBe($originalId)
        ->and($restoredVariant->trashed())->toBeFalse()
        ->and(ProductVariant::withTrashed()->where('product_id', $product->id)->count())->toBe(1);
});

test('submit to QC is blocked while needs_update or invalid variants exist', function () {
    $component = Livewire::actingAs($this->user)
        ->test('pages::seller.products-create')
        ->call('startNewProduct')
        ->set('vertical_id', $this->vertical->id)
        ->set('brand_id', $this->brand->id)
        ->set('title', 'Blocked QC Product')
        ->set('description', 'Valid product description for testing purposes')
        ->set('generic_name', 'T-shirt')
        ->set('net_quantity', '1')
        ->call('saveStep3');

    $draftId = $component->get('draftProductId');
    $draft = Product::find($draftId);

    // Flag needs_update manually
    $draft->update([
        'needs_update' => true,
        'needs_update_reason' => [
            ['variant_id' => null, 'code' => 'missing_price', 'message' => 'Price missing'],
        ],
    ]);

    $component->call('submitToQc')
        ->assertSet('currentStep', 4) // Redirected back to step 4
        ->assertSee('Cannot submit for QC');
});

test('partial draft saves in early steps but cannot submit to QC without variants', function () {
    $component = Livewire::actingAs($this->user)
        ->test('pages::seller.products-create')
        ->call('startNewProduct')
        ->set('vertical_id', $this->vertical->id)
        ->set('brand_id', $this->brand->id)
        ->set('title', 'Incomplete Product')
        ->set('description', 'Product with no variants')
        ->set('generic_name', 'T-shirt')
        ->set('net_quantity', '1')
        ->call('saveStep3')
        ->assertSet('currentStep', 4);

    $draftId = $component->get('draftProductId');
    expect($draftId)->not->toBeNull();

    // Draft is persisted in DB
    $draft = Product::find($draftId);
    expect($draft->title)->toBe('Incomplete Product')
        ->and($draft->variants)->toHaveCount(0);

    // Attempting submit to QC fails and returns to step 4 with error message
    $component->call('submitToQc')
        ->assertSet('currentStep', 4)
        ->assertSee('Cannot submit for QC');
});
