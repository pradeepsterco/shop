<?php

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
use App\Models\Vertical;
use App\Services\UpidGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * An approved product with one approved variant and an active listing from an
 * approved seller, so it has a buy box and is searchable.
 *
 * @param  array{title?: string, brand?: string, vertical?: string, category?: string, highlights?: array<int, string>, variant_attributes?: array<string, string>, selling_price?: int, mrp?: int, stock?: int}  $options
 */
function makeSearchableProduct(array $options = []): Product
{
    $seller = SellerProfile::query()->where('status', SellerStatus::Approved)->first()
        ?? SellerProfile::factory()->create(['status' => SellerStatus::Approved]);

    $brandName = $options['brand'] ?? 'Acme';
    $brand = Brand::firstOrCreate(
        ['slug' => Str::slug($brandName)],
        ['name' => $brandName, 'status' => BrandStatus::Approved],
    );

    $categoryName = $options['category'] ?? 'Electronics';
    $category = Category::firstOrCreate(
        ['slug' => Str::slug($categoryName)],
        ['name' => $categoryName],
    );

    $verticalName = $options['vertical'] ?? 'Smartphone';
    $vertical = Vertical::firstOrCreate(
        ['slug' => Str::slug($verticalName)],
        ['category_id' => $category->id, 'name' => $verticalName, 'hsn_code' => '8517', 'gst_rate' => 18],
    );

    $title = $options['title'] ?? 'Test Product';
    $product = Product::create([
        'vertical_id' => $vertical->id,
        'brand_id' => $brand->id,
        'title' => $title,
        'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
        'description' => 'Description',
        'highlights' => $options['highlights'] ?? [],
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => $options['variant_attributes'] ?? [],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SKU-'.Str::upper(Str::random(8)),
        'mrp' => $options['mrp'] ?? 150000,
        'selling_price' => $options['selling_price'] ?? 120000,
        'stock' => $options['stock'] ?? 10,
        'status' => ListingStatus::Active,
    ]);

    return $product->fresh();
}
