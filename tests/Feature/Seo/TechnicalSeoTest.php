<?php

namespace Tests\Feature\Seo;

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Models\ContentPage;
use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Http\Controllers\SitemapController;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicalSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SitemapController::clearCache();
    }

    public function test_robots_txt_returns_disallow_all_in_non_production(): void
    {
        $this->app['env'] = 'local';

        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee("User-agent: *");
        $response->assertSee("Disallow: /");
        $response->assertDontSee("Sitemap:");
    }

    public function test_robots_txt_serves_rules_and_sitemap_in_production(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee("User-agent: *");
        $response->assertSee("Disallow: /admin/");
        $response->assertSee("Disallow: /products?*q=*");
        $response->assertSee("Disallow: /products?*sort=*");
        $response->assertSee("Disallow: /products?*min_price=*");
        $response->assertSee("Disallow: /products?*page=*");
        $response->assertDontSee("Disallow: /cart");
        $response->assertDontSee("Disallow: /checkout");
        $response->assertDontSee("Disallow: /orders");
        $response->assertSee("Sitemap: " . rtrim(config('app.url'), '/') . "/sitemap.xml");
    }

    public function test_noindex_present_on_cart_page(): void
    {
        $response = $this->get(route('cart'));
        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_noindex_present_on_authenticated_private_pages(): void
    {
        $user = User::factory()->create();

        // Dashboard
        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, follow">', false);

        // Orders Index
        $response = $this->actingAs($user)->get(route('orders.index'));
        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, follow">', false);

        // Support Index
        $response = $this->actingAs($user)->get(route('support.index'));
        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, follow">', false);

        // Support Create
        $response = $this->actingAs($user)->get(route('support.create'));
        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_noindex_present_on_catalog_with_search_or_filter_or_sort_or_page(): void
    {
        // 1. Search Query
        $response = $this->get('/products?q=shoes');
        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, follow">', false);

        // 2. Sort option
        $response = $this->get('/products?sort=price_asc');
        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, follow">', false);

        // 3. Filter option (min_price)
        $response = $this->get('/products?min_price=500');
        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, follow">', false);

        // 4. Pagination (page=2)
        $response = $this->get('/products?page=2');
        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_noindex_absent_on_clean_indexable_storefront_pages(): void
    {
        $category = Category::create([
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true,
        ]);

        $vertical = Vertical::create([
            'category_id' => $category->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones',
            'hsn_code' => '8517',
            'gst_rate' => 18.0,
            'is_active' => true,
        ]);

        $brand = Brand::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'status' => BrandStatus::Approved,
        ]);

        $sellerUser = User::factory()->create();
        $seller = SellerProfile::factory()->create([
            'user_id' => $sellerUser->id,
            'store_name' => 'Acme Store',
            'store_slug' => 'acme-store',
            'status' => SellerStatus::Approved,
        ]);

        $product = Product::create([
            'vertical_id' => $vertical->id,
            'brand_id' => $brand->id,
            'title' => 'Acme Flagship Phone',
            'slug' => 'acme-flagship-phone',
            'description' => 'Great phone',
            'qc_status' => ProductQcStatus::Approved,
            'min_price' => 50000,
            'in_stock' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'upid' => 'ACME-PHONE-BLK',
            'variant_key' => 'color:black',
            'variant_attributes' => ['color' => 'black'],
            'qc_status' => VariantQcStatus::Approved,
        ]);

        $listing = Listing::create([
            'seller_profile_id' => $seller->id,
            'product_variant_id' => $variant->id,
            'sku' => 'ACME-BLK-SKU',
            'mrp' => 60000,
            'selling_price' => 50000,
            'stock' => 10,
            'status' => ListingStatus::Active,
        ]);

        $product->update(['buy_box_listing_id' => $listing->id]);

        $page = ContentPage::create([
            'slug' => 'about-us',
            'title' => 'About Us',
            'type' => PageType::Static,
            'status' => PageStatus::Published,
        ]);

        // Home page
        $res = $this->get(route('home'));
        $res->assertOk();
        $res->assertDontSee('<meta name="robots" content="noindex', false);

        // Clean products catalog
        $res = $this->get(route('products.index'));
        $res->assertOk();
        $res->assertDontSee('<meta name="robots" content="noindex', false);

        // Clean category
        $res = $this->get(route('products.index', ['category' => 'electronics']));
        $res->assertOk();
        $res->assertDontSee('<meta name="robots" content="noindex', false);

        // Clean vertical
        $res = $this->get(route('products.index', ['vertical' => 'smartphones']));
        $res->assertOk();
        $res->assertDontSee('<meta name="robots" content="noindex', false);

        // Product detail page
        $res = $this->get(route('product.show', ['slug' => 'acme-flagship-phone', 'upid' => 'ACME-PHONE-BLK']));
        $res->assertOk();
        $res->assertDontSee('<meta name="robots" content="noindex', false);

        // Static CMS page
        $res = $this->get(route('content.page', ['slug' => 'about-us']));
        $res->assertOk();
        $res->assertDontSee('<meta name="robots" content="noindex', false);

        // Seller profile
        $res = $this->get(route('sellers.show', ['slug' => 'acme-store']));
        $res->assertOk();
        $res->assertDontSee('<meta name="robots" content="noindex', false);
    }

    public function test_product_route_redirects_301_on_slug_mismatch(): void
    {
        $category = Category::create(['name' => 'Gadgets', 'slug' => 'gadgets', 'is_active' => true]);
        $vertical = Vertical::create([
            'category_id' => $category->id,
            'name' => 'Audio',
            'slug' => 'audio',
            'hsn_code' => '8518',
            'gst_rate' => 18.0,
            'is_active' => true,
        ]);
        $brand = Brand::create(['name' => 'AudioTech', 'slug' => 'audiotech', 'status' => BrandStatus::Approved]);

        $product = Product::create([
            'vertical_id' => $vertical->id,
            'brand_id' => $brand->id,
            'title' => 'Wireless Pro Headphones',
            'slug' => 'wireless-pro-headphones',
            'description' => 'Noise cancelling',
            'qc_status' => ProductQcStatus::Approved,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'upid' => 'AUD-PRO-BLK',
            'variant_key' => 'color:black',
            'qc_status' => VariantQcStatus::Approved,
        ]);

        // Requesting with an incorrect slug
        $response = $this->get('/old-wrong-slug/p/AUD-PRO-BLK');

        $response->assertStatus(301);
        $response->assertRedirect(route('product.show', [
            'slug' => 'wireless-pro-headphones',
            'upid' => 'AUD-PRO-BLK',
        ]));
    }

    public function test_sitemap_index_and_child_sitemaps_generation_and_filtering(): void
    {
        $category = Category::create(['name' => 'Tech', 'slug' => 'tech', 'is_active' => true]);
        $inactiveCategory = Category::create(['name' => 'Hidden Cat', 'slug' => 'hidden-cat', 'is_active' => false]);

        $vertical = Vertical::create([
            'category_id' => $category->id,
            'name' => 'Laptops',
            'slug' => 'laptops',
            'hsn_code' => '8471',
            'gst_rate' => 18.0,
            'is_active' => true,
        ]);

        $brand = Brand::create(['name' => 'Zenith', 'slug' => 'zenith', 'status' => BrandStatus::Approved]);

        $sellerUser = User::factory()->create();
        $approvedSeller = SellerProfile::factory()->create([
            'user_id' => $sellerUser->id,
            'store_name' => 'Zenith Store',
            'store_slug' => 'zenith-store',
            'status' => SellerStatus::Approved,
        ]);

        $pendingSellerUser = User::factory()->create();
        $pendingSeller = SellerProfile::factory()->create([
            'user_id' => $pendingSellerUser->id,
            'store_name' => 'Pending Store',
            'store_slug' => 'pending-store',
            'status' => SellerStatus::Pending,
        ]);

        // Approved product with buy box
        $product = Product::create([
            'vertical_id' => $vertical->id,
            'brand_id' => $brand->id,
            'title' => 'Zenith Ultrabook Pro',
            'slug' => 'zenith-ultrabook-pro',
            'description' => 'Fast laptop',
            'qc_status' => ProductQcStatus::Approved,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'upid' => 'ZEN-LAP-16',
            'variant_key' => 'ram:16gb',
            'qc_status' => VariantQcStatus::Approved,
        ]);

        $listing = Listing::create([
            'seller_profile_id' => $approvedSeller->id,
            'product_variant_id' => $variant->id,
            'sku' => 'ZEN-SKU-1',
            'mrp' => 90000,
            'selling_price' => 85000,
            'stock' => 5,
            'status' => ListingStatus::Active,
        ]);

        $product->update(['buy_box_listing_id' => $listing->id]);

        $image = ProductImage::create([
            'product_id' => $product->id,
            'path' => 'products/zenith.jpg',
            'status' => ProductImageStatus::Live,
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        // Unapproved product (must NOT appear in sitemap)
        $unapprovedProduct = Product::create([
            'vertical_id' => $vertical->id,
            'brand_id' => $brand->id,
            'title' => 'Draft Tablet',
            'slug' => 'draft-tablet',
            'description' => 'Not approved',
            'qc_status' => ProductQcStatus::Draft,
        ]);

        $unapprovedVariant = ProductVariant::create([
            'product_id' => $unapprovedProduct->id,
            'upid' => 'DRAFT-TAB-1',
            'variant_key' => 'storage:64gb',
            'qc_status' => VariantQcStatus::Draft,
        ]);

        // Published CMS page
        $page = ContentPage::create([
            'slug' => 'terms',
            'title' => 'Terms & Conditions',
            'type' => PageType::Static,
            'status' => PageStatus::Published,
        ]);

        // Draft CMS page (must NOT appear in sitemap)
        $draftPage = ContentPage::create([
            'slug' => 'secret-preview',
            'title' => 'Secret Preview',
            'type' => PageType::Static,
            'status' => PageStatus::Draft,
        ]);

        // 1. Verify Sitemap Index
        $indexRes = $this->get('/sitemap.xml');
        $indexRes->assertOk();
        $indexRes->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $indexRes->assertSee('<sitemapindex', false);
        $indexRes->assertSee(route('sitemap.pages'));
        $indexRes->assertSee(route('sitemap.categories'));
        $indexRes->assertSee(route('sitemap.sellers'));
        $indexRes->assertSee(route('sitemap.products', ['chunk' => 1]));
        $indexRes->assertSee('<lastmod>', false);

        // 2. Verify Pages Sitemap
        $pagesRes = $this->get('/sitemap-pages.xml');
        $pagesRes->assertOk();
        $pagesRes->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $pagesRes->assertSee(route('home'));
        $pagesRes->assertSee(route('grievance-officer'));
        $pagesRes->assertSee(route('content.page', ['slug' => 'terms']));
        $pagesRes->assertDontSee('secret-preview');

        // 3. Verify Categories Sitemap
        $catRes = $this->get('/sitemap-categories.xml');
        $catRes->assertOk();
        $catRes->assertSee(route('products.index', ['category' => 'tech']));
        $catRes->assertSee(route('products.index', ['vertical' => 'laptops']));
        $catRes->assertDontSee('hidden-cat');

        // 4. Verify Sellers Sitemap
        $sellerRes = $this->get('/sitemap-sellers.xml');
        $sellerRes->assertOk();
        $sellerRes->assertSee(route('sellers.show', ['slug' => 'zenith-store']));
        $sellerRes->assertDontSee('pending-store');

        // 5. Verify Products Sitemap
        $prodRes = $this->get('/sitemap-products-1.xml');
        $prodRes->assertOk();
        $prodRes->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $prodRes->assertSee(route('product.show', ['slug' => 'zenith-ultrabook-pro', 'upid' => 'ZEN-LAP-16']));
        $prodRes->assertSee('<image:image>', false);
        $prodRes->assertSee('<image:loc>' . htmlspecialchars($image->url(), ENT_XML1, 'UTF-8') . '</image:loc>', false);
        $prodRes->assertSee('<image:title>Zenith Ultrabook Pro</image:title>', false);
        $prodRes->assertDontSee('draft-tablet');
        $prodRes->assertDontSee('DRAFT-TAB-1');
    }

    public function test_sitemap_chunking_with_custom_chunk_size_and_404_on_out_of_bounds(): void
    {
        config(['seo.sitemap_chunk_size' => 2]);
        SitemapController::clearCache();

        $category = Category::create(['name' => 'Fashion', 'slug' => 'fashion', 'is_active' => true]);
        $vertical = Vertical::create([
            'category_id' => $category->id,
            'name' => 'Shirts',
            'slug' => 'shirts',
            'hsn_code' => '6205',
            'gst_rate' => 5.0,
            'is_active' => true,
        ]);
        $brand = Brand::create(['name' => 'TrendCo', 'slug' => 'trendco', 'status' => BrandStatus::Approved]);
        $sellerUser = User::factory()->create();
        $seller = SellerProfile::factory()->create([
            'user_id' => $sellerUser->id,
            'store_name' => 'Trend Store',
            'store_slug' => 'trend-store',
            'status' => SellerStatus::Approved,
        ]);

        // Create 3 active products with buy box listings to exceed chunk size of 2 (yielding 2 chunks: chunk 1 has 2, chunk 2 has 1)
        for ($i = 1; $i <= 3; $i++) {
            $product = Product::create([
                'vertical_id' => $vertical->id,
                'brand_id' => $brand->id,
                'title' => "Shirt Model {$i}",
                'slug' => "shirt-model-{$i}",
                'description' => "Description {$i}",
                'qc_status' => ProductQcStatus::Approved,
            ]);

            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'upid' => "SHIRT-{$i}-S",
                'variant_key' => "size:s{$i}",
                'qc_status' => VariantQcStatus::Approved,
            ]);

            $listing = Listing::create([
                'seller_profile_id' => $seller->id,
                'product_variant_id' => $variant->id,
                'sku' => "SKU-SHIRT-{$i}",
                'mrp' => 2000,
                'selling_price' => 1500,
                'stock' => 10,
                'status' => ListingStatus::Active,
            ]);

            $product->update(['buy_box_listing_id' => $listing->id]);
        }

        // 1. Verify Sitemap Index lists both chunk 1 and chunk 2
        $indexRes = $this->get('/sitemap.xml');
        $indexRes->assertOk();
        $indexRes->assertSee(route('sitemap.products', ['chunk' => 1]));
        $indexRes->assertSee(route('sitemap.products', ['chunk' => 2]));
        $indexRes->assertDontSee(route('sitemap.products', ['chunk' => 3]));

        // 2. Chunk 1 has 2 items
        $chunk1Res = $this->get('/sitemap-products-1.xml');
        $chunk1Res->assertOk();
        $chunk1Res->assertSee('shirt-model-1');
        $chunk1Res->assertSee('shirt-model-2');
        $chunk1Res->assertDontSee('shirt-model-3');

        // 3. Chunk 2 has 1 item
        $chunk2Res = $this->get('/sitemap-products-2.xml');
        $chunk2Res->assertOk();
        $chunk2Res->assertDontSee('shirt-model-1');
        $chunk2Res->assertDontSee('shirt-model-2');
        $chunk2Res->assertSee('shirt-model-3');

        // 4. Out of bounds chunk (chunk 3, 999) returns 404
        $chunk3Res = $this->get('/sitemap-products-3.xml');
        $chunk3Res->assertNotFound();

        $chunk999Res = $this->get('/sitemap-products-999.xml');
        $chunk999Res->assertNotFound();

        // 5. Non-numeric chunk returns 404 via route constraint
        $alphaChunkRes = $this->get('/sitemap-products-abc.xml');
        $alphaChunkRes->assertNotFound();
    }
}
