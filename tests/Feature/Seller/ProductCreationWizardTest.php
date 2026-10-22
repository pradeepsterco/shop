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
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Models\VerticalAttribute;
use Livewire\Livewire;

test('non-seller is redirected away from add product page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('seller.products.create'))
        ->assertRedirect(route('seller.apply'));
});

test('seller can search catalog by UPID and list instantly with zero QC', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
    ]);

    $brand = Brand::create(['name' => 'Nike', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Apparel']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Tees', 'hsn_code' => '6109', 'gst_rate' => 5.0]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Nike Dry Fit Tee',
        'description' => 'Running t-shirt',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => 'KHP9A8B7C6D5E4F',
        'variant_key' => 'color:black|size:l',
        'variant_attributes' => ['color' => 'black', 'size' => 'l'],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    Livewire::actingAs($user)
        ->test('pages::seller.products-create')
        ->set('catalog_search', 'KHP9A8B7C6D5E4F')
        ->assertSee('Nike Dry Fit Tee')
        ->call('openQuickSellModal', $variant->id)
        ->set('quick_sku', 'MY-SELLER-SKU-1')
        ->set('quick_mrp', '1299.00')
        ->set('quick_price', '999.00')
        ->set('quick_stock', '25')
        ->call('submitQuickSell')
        ->assertHasNoErrors()
        ->assertRedirect(route('seller.dashboard'));

    $listing = Listing::where('seller_profile_id', $seller->id)
        ->where('product_variant_id', $variant->id)
        ->first();

    expect($listing)->not->toBeNull()
        ->and($listing->sku)->toBe('MY-SELLER-SKU-1')
        ->and($listing->mrp)->toBe(129900)
        ->and($listing->selling_price)->toBe(99900)
        ->and($listing->stock)->toBe(25)
        ->and($listing->status)->toBe(ListingStatus::Active);
});

test('seller can create new product through 5-step wizard and submit to QC', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
    ]);

    $brand = Brand::create(['name' => 'Generic', 'slug' => 'generic', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Fashion']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'T-Shirt', 'hsn_code' => '6109', 'gst_rate' => 5.0]);

    // Setup attributes
    $sizeAttr = VerticalAttribute::create([
        'vertical_id' => $vert->id,
        'name' => 'Size',
        'code' => 'size',
        'type' => AttributeType::Select,
        'is_variant' => true,
        'options' => [
            ['label' => 'M', 'value' => 'm', 'is_active' => true],
            ['label' => 'L', 'value' => 'l', 'is_active' => true],
        ],
    ]);

    $fabricAttr = VerticalAttribute::create([
        'vertical_id' => $vert->id,
        'name' => 'Fabric',
        'code' => 'fabric',
        'type' => AttributeType::Select,
        'is_variant' => false,
        'options' => [
            ['label' => '100% Cotton', 'value' => 'cotton', 'is_active' => true],
        ],
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::seller.products-create')
        ->call('startNewProduct')
        // Step 1: Vertical
        ->call('selectVertical', $vert->id)
        ->assertSet('currentStep', 2)
        // Step 2: Brand
        ->call('selectBrand', $brand->id)
        ->assertSet('currentStep', 3)
        // Step 3: Details & Specs
        ->set('title', 'Men Round Neck Cotton T-Shirt')
        ->set('description', 'High quality cotton everyday t-shirt with premium finish.')
        ->set('highlights', ['100% combed cotton', 'Regular fit'])
        ->set('specs.fabric', 'cotton')
        ->call('saveStep3')
        ->assertSet('currentStep', 4);

    // Step 4: Variants selection
    $component->set('selectedVariantOptions', ['size' => ['m', 'l']])
        ->call('generateVariantGrid');

    expect($component->get('variantRows'))->toHaveCount(2);

    // Batch apply prices & stock
    $component->set('batch_mrp', '899.00')
        ->set('batch_price', '599.00')
        ->set('batch_stock', '15')
        ->call('applyToAll');

    $rows = $component->get('variantRows');
    $rows[0]['sku'] = 'NIKE-TEE-M';
    $rows[1]['sku'] = 'NIKE-TEE-L';
    $component->set('variantRows', $rows)
        ->call('saveStep4')
        ->assertSet('currentStep', 5);

    // Manually attach a product image to simulate file upload
    $draftId = $component->get('draftProductId');
    expect($draftId)->not->toBeNull();

    ProductImage::create([
        'product_id' => $draftId,
        'path' => 'product-images/test.jpg',
        'sort_order' => 1,
        'is_primary' => true,
    ]);

    // Step 5: Submit to QC
    $component->call('submitToQc')
        ->assertRedirect(route('seller.dashboard'));

    $product = Product::with(['variants', 'images'])->find($draftId);
    expect($product->qc_status)->toBe(ProductQcStatus::QcPending)
        ->and($product->specs)->toBe(['fabric' => 'cotton'])
        ->and($product->variants)->toHaveCount(2)
        ->and($product->variants[0]->qc_status)->toBe(VariantQcStatus::QcPending);
});

test('selling price cannot exceed MRP in variant matrix', function () {
    $user = User::factory()->create();
    SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
    ]);

    $brand = Brand::create(['name' => 'Generic', 'slug' => 'generic', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Fashion']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'T-Shirt', 'hsn_code' => '6109', 'gst_rate' => 5.0]);

    Livewire::actingAs($user)
        ->test('pages::seller.products-create')
        ->call('startNewProduct')
        ->set('vertical_id', $vert->id)
        ->set('brand_id', $brand->id)
        ->set('title', 'Valid Product Title')
        ->set('description', 'Long enough description for testing')
        ->set('variantRows', [
            [
                'key' => 'size:m',
                'variant_attributes' => ['size' => 'm'],
                'sku' => 'SKU-001',
                'mrp' => '500.00',
                'selling_price' => '600.00', // Invalid: Price > MRP
                'stock' => '5',
                'barcode' => '',
                'weight' => '',
            ],
        ])
        ->call('saveStep4')
        ->assertHasErrors(['variantRows.0.selling_price']);
});

test('seller can toggle variant options to generate and remove grid rows', function () {
    $user = User::factory()->create();
    SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
    ]);

    $brand = Brand::create(['name' => 'Generic', 'slug' => 'generic', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Fashion']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'T-Shirt', 'hsn_code' => '6109', 'gst_rate' => 5.0]);

    VerticalAttribute::create([
        'vertical_id' => $vert->id,
        'name' => 'Size',
        'code' => 'size',
        'type' => AttributeType::Select,
        'is_variant' => true,
        'options' => [
            ['label' => 'M', 'value' => 'm', 'is_active' => true],
            ['label' => 'L', 'value' => 'l', 'is_active' => true],
        ],
    ]);

    $test = Livewire::actingAs($user)
        ->test('pages::seller.products-create')
        ->call('startNewProduct')
        ->set('vertical_id', $vert->id)
        ->set('brand_id', $brand->id)
        ->set('title', 'Test Tee')
        ->set('currentStep', 4);

    // Toggle size:m -> 1 row generated
    $test->call('toggleVariantOption', 'size', 'm');
    expect($test->get('variantRows'))->toHaveCount(1)
        ->and($test->instance()->isVariantOptionSelected('size', 'm'))->toBeTrue()
        ->and($test->instance()->isVariantOptionSelected('size', 'l'))->toBeFalse();

    // Toggle size:l -> 2 rows generated
    $test->call('toggleVariantOption', 'size', 'l');
    expect($test->get('variantRows'))->toHaveCount(2)
        ->and($test->instance()->isVariantOptionSelected('size', 'l'))->toBeTrue();

    // Toggle size:m again -> unselects m, 1 row left (l)
    $test->call('toggleVariantOption', 'size', 'm');
    expect($test->get('variantRows'))->toHaveCount(1)
        ->and($test->instance()->isVariantOptionSelected('size', 'm'))->toBeFalse();

    // Remove remaining row via removeVariantRow
    $test->call('removeVariantRow', 0);
    expect($test->get('variantRows'))->toBeEmpty();
});

test('variant matrix rejects duplicate SKUs across variants', function () {
    $user = User::factory()->create();
    SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
    ]);

    $brand = Brand::create(['name' => 'Generic', 'slug' => 'generic', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Fashion']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'T-Shirt', 'hsn_code' => '6109', 'gst_rate' => 5.0]);

    Livewire::actingAs($user)
        ->test('pages::seller.products-create')
        ->call('startNewProduct')
        ->set('vertical_id', $vert->id)
        ->set('brand_id', $brand->id)
        ->set('title', 'Duplicate SKU Test')
        ->set('variantRows', [
            [
                'key' => 'size:m',
                'variant_attributes' => ['size' => 'm'],
                'sku' => 'SAME-SKU-123',
                'mrp' => '500.00',
                'selling_price' => '400.00',
                'stock' => '5',
                'barcode' => '',
                'weight' => '',
            ],
            [
                'key' => 'size:l',
                'variant_attributes' => ['size' => 'l'],
                'sku' => 'SAME-SKU-123', // duplicate
                'mrp' => '500.00',
                'selling_price' => '400.00',
                'stock' => '5',
                'barcode' => '',
                'weight' => '',
            ],
        ])
        ->call('saveStep4')
        ->assertHasErrors(['variantRows.1.sku']);
});

test('resaving step 4 does not trigger false positive sku collision on existing listings for this draft product', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
    ]);

    $brand = Brand::create(['name' => 'Nike', 'slug' => 'nike', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Sports']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Jersey', 'hsn_code' => '6109', 'gst_rate' => 5.0]);

    VerticalAttribute::create([
        'vertical_id' => $vert->id,
        'name' => 'Size',
        'code' => 'size',
        'type' => AttributeType::Select,
        'is_variant' => true,
        'options' => [
            ['label' => 'L', 'value' => 'l', 'is_active' => true],
            ['label' => 'XL', 'value' => 'xl', 'is_active' => true],
        ],
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::seller.products-create')
        ->call('startNewProduct')
        ->set('vertical_id', $vert->id)
        ->set('brand_id', $brand->id)
        ->set('title', 'Dry Fit Jersey')
        ->set('description', 'High performance sports jersey')
        ->set('currentStep', 4)
        ->set('variantRows', [
            [
                'key' => 'size:l',
                'variant_attributes' => ['size' => 'l'],
                'sku' => 'dfsd',
                'mrp' => '2999.00',
                'selling_price' => '2499.00',
                'stock' => '10',
                'barcode' => '',
                'weight' => '',
            ],
            [
                'key' => 'size:xl',
                'variant_attributes' => ['size' => 'xl'],
                'sku' => 'sdfsdf',
                'mrp' => '2999.00',
                'selling_price' => '2499.00',
                'stock' => '10',
                'barcode' => '',
                'weight' => '',
            ],
        ]);

    // First time clicking Continue to Review -> (saveStep4)
    $component->call('saveStep4')
        ->assertHasNoErrors()
        ->assertSet('currentStep', 5);

    // Verify variants and listings exist in DB
    $draftId = $component->get('draftProductId');
    $product = Product::with('variants.listings')->findOrFail($draftId);
    expect($product->variants)->toHaveCount(2)
        ->and(Listing::where('seller_profile_id', $seller->id)->count())->toBe(2);

    // Seller goes back to Step 4 and clicks Continue to Review -> again
    $component->set('currentStep', 4)
        ->call('saveStep4')
        ->assertHasNoErrors()
        ->assertSet('currentStep', 5);
});
