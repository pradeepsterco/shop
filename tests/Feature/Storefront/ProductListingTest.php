<?php

use App\Console\Commands\CatalogAuditCommand;
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
use App\Services\ProductBuyBoxService;
use App\Services\UpidGenerator;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SellerSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');

    // Create default dummy placeholder SVG
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400"><rect width="400" height="400" fill="#eee"/></svg>';
    Storage::disk('public')->put('products/placeholder.svg', $svg);
});

test('storefront listing shows approved products with active listings including out of stock', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Demo Toy Store',
    ]);

    $brand = Brand::create(['name' => 'TestBrand', 'slug' => 'test-brand', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Toys', 'slug' => 'toys']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Puzzles', 'slug' => 'puzzles', 'hsn_code' => '9504', 'gst_rate' => 12]);

    // Product 1: In stock
    $p1 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'In-Stock Puzzle 1000 Pieces',
        'slug' => 'in-stock-puzzle',
        'description' => 'Great puzzle',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    $v1 = ProductVariant::create([
        'product_id' => $p1->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['pieces' => '1000'],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $v1->id,
        'sku' => 'SKU-001',
        'mrp' => 150000,
        'selling_price' => 120000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    // Product 2: Out of stock (stock = 0)
    $p2 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Out-Of-Stock Vintage Puzzle',
        'slug' => 'out-of-stock-puzzle',
        'description' => 'Rare puzzle',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    $v2 = ProductVariant::create([
        'product_id' => $p2->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['pieces' => '500'],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $v2->id,
        'sku' => 'SKU-002',
        'mrp' => 200000,
        'selling_price' => 180000,
        'stock' => 0,
        'status' => ListingStatus::Active,
    ]);

    // Product 3: No listings (should NOT show)
    Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Unlisted Puzzle Without Offers',
        'slug' => 'unlisted-puzzle',
        'description' => 'No seller',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    // Product 4: Draft (should NOT show)
    Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Draft Review Puzzle',
        'slug' => 'draft-puzzle',
        'description' => 'Draft',
        'qc_status' => ProductQcStatus::Draft,
    ]);

    app(ProductBuyBoxService::class)->refreshAll();

    Livewire::test('pages::storefront.product-index')
        ->assertSee('In-Stock Puzzle 1000 Pieces')
        ->assertSee('Out-Of-Stock Vintage Puzzle')
        ->assertSee('Out of stock')
        ->assertDontSee('Unlisted Puzzle Without Offers')
        ->assertDontSee('Draft Review Puzzle');
});

test('buy box calculation prefers cheapest in-stock listing over cheaper out-of-stock listing', function () {
    $user1 = User::factory()->create();
    $seller1 = SellerProfile::factory()->create(['user_id' => $user1->id, 'status' => SellerStatus::Approved]);
    $user2 = User::factory()->create();
    $seller2 = SellerProfile::factory()->create(['user_id' => $user2->id, 'status' => SellerStatus::Approved]);

    $brand = Brand::create(['name' => 'Hasbro', 'slug' => 'hasbro', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Games', 'slug' => 'games']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Board Game', 'slug' => 'board-game', 'hsn_code' => '9504', 'gst_rate' => 12]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Monopoly Empire Game',
        'slug' => 'monopoly-empire-game',
        'description' => 'Board game',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['edition' => 'standard'],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    // Listing 1: Cheaper but OUT OF STOCK (₹800, stock 0)
    $listingOos = Listing::create([
        'seller_profile_id' => $seller1->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SKU-CHEAP-OOS',
        'mrp' => 200000,
        'selling_price' => 80000,
        'stock' => 0,
        'status' => ListingStatus::Active,
    ]);

    // Listing 2: More expensive but IN STOCK (₹1,200, stock 5)
    $listingInStock = Listing::create([
        'seller_profile_id' => $seller2->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SKU-EXP-INSTOCK',
        'mrp' => 200000,
        'selling_price' => 120000,
        'stock' => 5,
        'status' => ListingStatus::Active,
    ]);

    $product->refresh();
    expect($product->buy_box_listing_id)->toBe($listingInStock->id)
        ->and($product->min_price)->toBe(120000)
        ->and($product->in_stock)->toBeTrue();

    // When Listing 2 also goes out of stock, buy box falls back to the cheapest active listing (Listing 1)
    $listingInStock->update(['stock' => 0]);
    $product->refresh();
    expect($product->buy_box_listing_id)->toBe($listingOos->id)
        ->and($product->min_price)->toBe(80000)
        ->and($product->in_stock)->toBeFalse();
});

test('in stock filter excludes out of stock products', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $user->id, 'status' => SellerStatus::Approved]);
    $brand = Brand::create(['name' => 'BrandTest', 'slug' => 'brand-test', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Toys', 'slug' => 'toys']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Blocks', 'slug' => 'blocks', 'hsn_code' => '9503', 'gst_rate' => 18]);

    // In-stock product
    $pInStock = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'In-Stock Blocks Set',
        'slug' => 'in-stock-blocks',
        'description' => 'Blocks',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    $v1 = ProductVariant::create([
        'product_id' => $pInStock->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['theme' => 'city'],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $v1->id,
        'sku' => 'SKU-IS',
        'mrp' => 100000,
        'selling_price' => 90000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    // Out-of-stock product
    $pOos = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Out-Of-Stock Blocks Set',
        'slug' => 'out-of-stock-blocks',
        'description' => 'Blocks',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    $v2 = ProductVariant::create([
        'product_id' => $pOos->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['theme' => 'space'],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $v2->id,
        'sku' => 'SKU-OOS',
        'mrp' => 100000,
        'selling_price' => 80000,
        'stock' => 0,
        'status' => ListingStatus::Active,
    ]);

    Livewire::test('pages::storefront.product-index')
        ->assertSee('In-Stock Blocks Set')
        ->assertSee('Out-Of-Stock Blocks Set')
        ->set('in_stock', true)
        ->assertSee('In-Stock Blocks Set')
        ->assertDontSee('Out-Of-Stock Blocks Set');
});

test('same variant attribute filters guarantee: Black-S + Red-M returns nothing for Black+M', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $user->id, 'status' => SellerStatus::Approved]);
    $brand = Brand::create(['name' => 'NikeApparel', 'slug' => 'nike-apparel', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Fashion', 'slug' => 'fashion']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'T-Shirt', 'slug' => 't-shirt', 'hsn_code' => '6109', 'gst_rate' => 5]);

    VerticalAttribute::create([
        'vertical_id' => $vert->id,
        'name' => 'Color',
        'code' => 'color',
        'type' => AttributeType::Select,
        'is_variant' => true,
        'options' => [
            ['label' => 'Black', 'value' => 'black', 'is_active' => true],
            ['label' => 'Red', 'value' => 'red', 'is_active' => true],
        ],
    ]);

    VerticalAttribute::create([
        'vertical_id' => $vert->id,
        'name' => 'Size',
        'code' => 'size',
        'type' => AttributeType::Select,
        'is_variant' => true,
        'options' => [
            ['label' => 'S', 'value' => 's', 'is_active' => true],
            ['label' => 'M', 'value' => 'm', 'is_active' => true],
        ],
    ]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Nike Split Color Sport Tee',
        'slug' => 'nike-split-color-sport-tee',
        'description' => 'Sport tee',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    // Variant 1: Black and S
    $v1 = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'black', 'size' => 's'],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $v1->id,
        'sku' => 'SKU-BLK-S',
        'mrp' => 150000,
        'selling_price' => 120000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    // Variant 2: Red and M
    $v2 = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['color' => 'red', 'size' => 'm'],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $v2->id,
        'sku' => 'SKU-RED-M',
        'mrp' => 150000,
        'selling_price' => 120000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    app(ProductBuyBoxService::class)->refreshAll();

    // 1. Black + M does NOT exist on any single variant -> Product must NOT be returned!
    Livewire::test('pages::storefront.product-index', ['vertical' => 't-shirt'])
        ->set('attrs', ['color' => ['black'], 'size' => ['m']])
        ->assertDontSee('Nike Split Color Sport Tee');

    // 2. Black + S matches Variant 1 -> Product IS returned!
    Livewire::test('pages::storefront.product-index', ['vertical' => 't-shirt'])
        ->set('attrs', ['color' => ['black'], 'size' => ['s']])
        ->assertSee('Nike Split Color Sport Tee');

    // 3. (Black OR Red) + M matches Variant 2 -> Product IS returned!
    Livewire::test('pages::storefront.product-index', ['vertical' => 't-shirt'])
        ->set('attrs', ['color' => ['black', 'red'], 'size' => ['m']])
        ->assertSee('Nike Split Color Sport Tee');
});

test('attribute filters are shown only when exactly one vertical is selected', function () {
    $cat = Category::create(['name' => 'Toys', 'slug' => 'toys']);
    $vert1 = Vertical::create(['category_id' => $cat->id, 'name' => 'Board Game', 'slug' => 'board-game', 'hsn_code' => '9504', 'gst_rate' => 12]);
    $vert2 = Vertical::create(['category_id' => $cat->id, 'name' => 'Action Figure', 'slug' => 'action-figure', 'hsn_code' => '9503', 'gst_rate' => 18]);

    VerticalAttribute::create([
        'vertical_id' => $vert1->id,
        'name' => 'Edition',
        'code' => 'edition',
        'type' => AttributeType::Select,
        'is_variant' => true,
        'options' => [['label' => 'Standard', 'value' => 'standard', 'is_active' => true]],
    ]);

    // Case 1: No vertical selected -> dynamicAttributes is empty, section not rendered
    $lw = Livewire::test('pages::storefront.product-index');
    expect($lw->get('dynamicAttributes'))->toBeEmpty();
    $lw->assertDontSee('Board Game Options');

    // Case 2: Parent category with multiple verticals selected -> dynamicAttributes empty
    $lw->set('category', 'toys');
    expect($lw->get('dynamicAttributes'))->toBeEmpty();
    $lw->assertDontSee('Board Game Options');

    // Case 3: Specific vertical selected -> dynamicAttributes populated and section rendered
    $lw->set('vertical', 'board-game');
    expect($lw->get('dynamicAttributes'))->toHaveCount(1);
    $lw->assertSee('Board Game Options');
});

test('category filter includes all descendant categories verticals', function () {
    $parentCat = Category::create(['name' => 'Toys & Games', 'slug' => 'toys-games']);
    $childCat = Category::create(['name' => 'Games', 'slug' => 'games', 'parent_id' => $parentCat->id]);
    $vert = Vertical::create(['category_id' => $childCat->id, 'name' => 'Board Game', 'slug' => 'board-game', 'hsn_code' => '9504', 'gst_rate' => 12]);

    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $user->id, 'status' => SellerStatus::Approved]);
    $brand = Brand::create(['name' => 'Hasbro', 'slug' => 'hasbro', 'status' => BrandStatus::Approved]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Scrabble Deluxe Edition',
        'slug' => 'scrabble-deluxe',
        'description' => 'Word game',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['edition' => 'deluxe'],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SKU-SCRABBLE',
        'mrp' => 200000,
        'selling_price' => 160000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    app(ProductBuyBoxService::class)->refreshAll();

    Livewire::test('pages::storefront.product-index', ['category' => 'toys-games'])
        ->assertSee('Scrabble Deluxe Edition');
});

test('price filter in rupees and discount filter operate accurately', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $user->id, 'status' => SellerStatus::Approved]);
    $brand = Brand::create(['name' => 'BrandP', 'slug' => 'brand-p', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'CatP', 'slug' => 'cat-p']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'VertP', 'slug' => 'vert-p', 'hsn_code' => '9504', 'gst_rate' => 12]);

    // Product 1: Price ₹400 (40,000 paise), MRP ₹500, discount 20%
    $p1 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Budget Card Game',
        'slug' => 'budget-card-game',
        'description' => 'Card game',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    $v1 = ProductVariant::create(['product_id' => $p1->id, 'upid' => UpidGenerator::generate(), 'variant_attributes' => ['edition' => 'standard'], 'qc_status' => VariantQcStatus::Approved]);
    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $v1->id,
        'sku' => 'SKU-P1',
        'mrp' => 50000,
        'selling_price' => 40000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    // Product 2: Price ₹3,500 (350,000 paise), MRP ₹7,000, discount 50%
    $p2 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Luxury Collector Game',
        'slug' => 'luxury-collector-game',
        'description' => 'Collector game',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    $v2 = ProductVariant::create(['product_id' => $p2->id, 'upid' => UpidGenerator::generate(), 'variant_attributes' => ['edition' => 'deluxe'], 'qc_status' => VariantQcStatus::Approved]);
    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $v2->id,
        'sku' => 'SKU-P2',
        'mrp' => 700000,
        'selling_price' => 350000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    app(ProductBuyBoxService::class)->refreshAll();

    // Filter price: ₹1000 - ₹5000
    Livewire::test('pages::storefront.product-index')
        ->set('min_price', '1000')
        ->set('max_price', '5000')
        ->assertSee('Luxury Collector Game')
        ->assertDontSee('Budget Card Game');

    // Filter discount: 30%+
    Livewire::test('pages::storefront.product-index')
        ->set('min_discount', '30')
        ->assertSee('Luxury Collector Game')
        ->assertDontSee('Budget Card Game');
});

test('sorting keeps in-stock items before out-of-stock items', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $user->id, 'status' => SellerStatus::Approved]);
    $brand = Brand::create(['name' => 'BrandSort', 'slug' => 'brand-sort', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'CatSort', 'slug' => 'cat-sort']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'VertSort', 'slug' => 'vert-sort', 'hsn_code' => '9504', 'gst_rate' => 12]);

    // In-stock item with high price ₹5,000
    $pInStock = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Expensive In-Stock Product',
        'slug' => 'expensive-in-stock',
        'description' => 'Test',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    $v1 = ProductVariant::create(['product_id' => $pInStock->id, 'upid' => UpidGenerator::generate(), 'variant_attributes' => ['edition' => 'standard'], 'qc_status' => VariantQcStatus::Approved]);
    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $v1->id,
        'sku' => 'SKU-SORT-1',
        'mrp' => 500000,
        'selling_price' => 500000,
        'stock' => 10,
        'status' => ListingStatus::Active,
    ]);

    // Out-of-stock item with low price ₹200
    $pOos = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Cheap Out-of-Stock Product',
        'slug' => 'cheap-oos',
        'description' => 'Test',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    $v2 = ProductVariant::create(['product_id' => $pOos->id, 'upid' => UpidGenerator::generate(), 'variant_attributes' => ['edition' => 'standard'], 'qc_status' => VariantQcStatus::Approved]);
    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $v2->id,
        'sku' => 'SKU-SORT-2',
        'mrp' => 20000,
        'selling_price' => 20000,
        'stock' => 0,
        'status' => ListingStatus::Active,
    ]);

    app(ProductBuyBoxService::class)->refreshAll();

    // Even when sorted price_asc (low to high), the in-stock item must come first!
    $component = Livewire::test('pages::storefront.product-index')
        ->set('sort', 'price_asc');

    $products = $component->get('products')->items();
    expect($products[0]->id)->toBe($pInStock->id)
        ->and($products[1]->id)->toBe($pOos->id);
});

test('malicious input is safely sanitized and ignored without throwing errors', function () {
    $cat = Category::create(['name' => 'Tech', 'slug' => 'tech']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Gadget', 'slug' => 'gadget', 'hsn_code' => '8517', 'gst_rate' => 18]);

    // Attempt injection in attrs, oversized arrays, invalid sort strings
    Livewire::test('pages::storefront.product-index', ['vertical' => 'gadget'])
        ->set('attrs', [
            '$..hack" injection' => ['drop table', '1=1'],
            'non_existent_code' => ['value1'],
        ])
        ->set('sort', "invalid'; DROP TABLE products;--")
        ->assertOk();
});

test('product card links to product show with buy box upid and has no add to cart button', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create(['user_id' => $user->id, 'status' => SellerStatus::Approved]);
    $brand = Brand::create(['name' => 'LegoOfficial', 'slug' => 'lego-official', 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Blocks', 'slug' => 'blocks']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Sets', 'slug' => 'sets', 'hsn_code' => '9503', 'gst_rate' => 18]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Lego Space Station Kit',
        'slug' => 'lego-space-station-kit',
        'description' => 'Space station',
        'qc_status' => ProductQcStatus::Approved,
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => 'KP1234567890',
        'variant_attributes' => ['theme' => 'space'],
        'qc_status' => VariantQcStatus::Approved,
    ]);
    Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SKU-LEGO-SP',
        'mrp' => 1000000,
        'selling_price' => 800000,
        'stock' => 15,
        'status' => ListingStatus::Active,
    ]);

    app(ProductBuyBoxService::class)->refreshAll();

    Livewire::test('pages::storefront.product-index')
        ->assertSee('Lego Space Station Kit')
        ->assertSee('/lego-space-station-kit/p/KP1234567890')
        ->assertDontSee('Add to cart')
        ->assertDontSee('Add to Cart')
        ->assertDontSee('add-to-cart');

    // Also test product show page loads with correct details and buttons
    Livewire::test('pages::storefront.product-show', [
        'slug' => 'lego-space-station-kit',
        'upid' => 'KP1234567890',
    ])
        ->assertSee('Lego Space Station Kit')
        ->assertSee('₹8,000')
        ->assertSee('₹10,000')
        ->assertSee('20% OFF')
        ->assertSee('In Stock')
        ->assertSee('ADD TO CART')
        ->assertSee('BUY NOW');
});

test('seeding database with CatalogSeeder and ProductSeeder passes catalog audit with 0 violations', function () {
    $this->seed(SellerSeeder::class);
    $this->seed(CatalogSeeder::class);
    $this->seed(ProductSeeder::class);

    $this->artisan(CatalogAuditCommand::class)
        ->expectsOutputToContain('All catalog integrity checks PASSED with 0 violations.')
        ->assertExitCode(0);
});
