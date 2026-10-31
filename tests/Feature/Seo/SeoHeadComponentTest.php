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
use App\Models\Brand;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\SEO\SeoData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoHeadComponentTest extends TestCase
{
    use RefreshDatabase;

    public function test_seo_data_title_formatting_prevents_duplicate_brand(): void
    {
        $seo1 = SeoData::make(title: 'Wireless Headphones');
        $this->assertEquals('Wireless Headphones - ' . brand_name(), $seo1->formattedTitle());

        // Already has brand suffix
        $seo2 = SeoData::make(title: 'Wireless Headphones - ' . brand_name());
        $this->assertEquals('Wireless Headphones - ' . brand_name(), $seo2->formattedTitle());

        // Empty title
        $seo3 = SeoData::make(title: '');
        $this->assertEquals(brand_name(), $seo3->formattedTitle());
    }

    public function test_seo_data_description_sanitizing_and_word_boundary_truncation(): void
    {
        $htmlDesc = '<p>Check out this <strong>fantastic</strong> product with &amp; rich details!</p>';
        $this->assertEquals('Check out this fantastic product with & rich details!', SeoData::sanitizeDescription($htmlDesc));

        $longText = str_repeat('Word ', 40); // 200 chars
        $sanitized = SeoData::sanitizeDescription($longText, 100);
        $this->assertLessThanOrEqual(103, strlen($sanitized)); // 100 + '...'
        $this->assertStringEndsWith('...', $sanitized);
        $this->assertFalse(str_ends_with(substr($sanitized, 0, -3), ' ')); // no trailing space before ...
    }

    public function test_seo_data_canonical_sanitizing_strips_tracking_params(): void
    {
        $raw = 'https://example.com/products?utm_source=google&utm_medium=cpc&utm_campaign=summer&category=shoes&fbclid=xyz123&gclid=abc456&ref=affiliate';
        $clean = SeoData::sanitizeCanonicalUrl($raw);

        $this->assertEquals('https://example.com/products?category=shoes', $clean);
    }

    public function test_product_detail_page_head_tags_and_metadata(): void
    {
        $category = Category::create(['name' => 'Audio', 'slug' => 'audio', 'is_active' => true]);
        $vertical = Vertical::create([
            'category_id' => $category->id,
            'name' => 'Headphones',
            'slug' => 'headphones',
            'hsn_code' => '8518',
            'gst_rate' => 18.0,
            'is_active' => true,
        ]);
        $brand = Brand::create(['name' => 'Bosey', 'slug' => 'bosey', 'status' => BrandStatus::Approved]);

        $sellerUser = User::factory()->create();
        $seller = SellerProfile::factory()->create([
            'user_id' => $sellerUser->id,
            'store_name' => 'Audio Central',
            'store_slug' => 'audio-central',
            'status' => SellerStatus::Approved,
        ]);

        $product = Product::create([
            'vertical_id' => $vertical->id,
            'brand_id' => $brand->id,
            'title' => 'ComfortQuiet 45',
            'slug' => 'comfortquiet-45',
            'description' => 'World-class noise cancellation headphones with high-fidelity audio.',
            'highlights' => ['Active Noise Cancelling', '24-hour battery life'],
            'qc_status' => ProductQcStatus::Approved,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'upid' => 'CQ45-BLK',
            'variant_key' => 'color:black',
            'qc_status' => VariantQcStatus::Approved,
        ]);

        $listing = Listing::create([
            'seller_profile_id' => $seller->id,
            'product_variant_id' => $variant->id,
            'sku' => 'CQ45-SKU',
            'mrp' => 29900,
            'selling_price' => 24900,
            'stock' => 10,
            'status' => ListingStatus::Active,
        ]);

        $product->update(['buy_box_listing_id' => $listing->id]);

        $image = ProductImage::create([
            'product_id' => $product->id,
            'path' => 'products/cq45.jpg',
            'status' => ProductImageStatus::Live,
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $response = $this->get(route('product.show', [
            'slug' => 'comfortquiet-45',
            'upid' => 'CQ45-BLK',
        ]));

        $response->assertOk();

        // 1. Title
        $expectedTitle = "ComfortQuiet 45 | Bosey - " . brand_name();
        $response->assertSee("<title>{$expectedTitle}</title>", false);

        // 2. Meta description
        $response->assertSee('<meta name="description" content="World-class noise cancellation headphones with high-fidelity audio.">', false);

        // 3. Canonical URL
        $expectedCanonical = route('product.show', ['slug' => 'comfortquiet-45', 'upid' => 'CQ45-BLK']);
        $response->assertSee('<link rel="canonical" href="' . $expectedCanonical . '">', false);

        // 4. Robots meta
        $response->assertSee('<meta name="robots" content="index, follow, max-image-preview:large">', false);

        // 5. Open Graph tags
        $response->assertSee('<meta property="og:type" content="product">', false);
        $response->assertSee('<meta property="og:title" content="ComfortQuiet 45 | Bosey">', false);
        $response->assertSee('<meta property="og:url" content="' . $expectedCanonical . '">', false);
        $response->assertSee('<meta property="og:image" content="' . $image->url() . '">', false);

        // 6. Preconnect font
        $response->assertSee('<link rel="preconnect" href="https://fonts.googleapis.com">', false);
    }

    public function test_static_page_seo_with_custom_meta_and_fallback(): void
    {
        // 1. Page with custom SEO title and description
        $pageWithSeo = ContentPage::create([
            'slug' => 'privacy-policy',
            'title' => 'Privacy Policy',
            'type' => PageType::Static,
            'status' => PageStatus::Published,
            'seo_title' => 'Our Privacy Commitment',
            'seo_description' => 'Learn how we protect your personal information and uphold strict privacy standards.',
            'content' => ['body' => 'Full privacy policy content here.'],
        ]);

        $res1 = $this->get(route('content.page', ['slug' => 'privacy-policy']));
        $res1->assertOk();
        $res1->assertSee("<title>Our Privacy Commitment - " . brand_name() . "</title>", false);
        $res1->assertSee('<meta name="description" content="Learn how we protect your personal information and uphold strict privacy standards.">', false);
        $res1->assertSee('<link rel="canonical" href="' . route('content.page', ['slug' => 'privacy-policy']) . '">', false);

        // 2. Page without custom SEO metadata (falls back to title, no meta description tag)
        $pageFallback = ContentPage::create([
            'slug' => 'terms-of-service',
            'title' => 'Terms of Service',
            'type' => PageType::Static,
            'status' => PageStatus::Published,
            'content' => ['body' => 'These are the official terms of service for our users.'],
        ]);

        $res2 = $this->get(route('content.page', ['slug' => 'terms-of-service']));
        $res2->assertOk();
        $res2->assertSee("<title>Terms of Service - " . brand_name() . "</title>", false);
        $res2->assertDontSee('<meta name="description"', false);
    }

    public function test_catalog_canonical_and_robots_behavior(): void
    {
        $category = Category::create(['name' => 'Fashion', 'slug' => 'fashion', 'is_active' => true]);

        // 1. Clean Category
        $resCategory = $this->get(route('products.index', ['category' => 'fashion']));
        $resCategory->assertOk();
        $resCategory->assertSee('<link rel="canonical" href="' . route('products.index', ['category' => 'fashion']) . '">', false);
        $resCategory->assertSee('<meta name="robots" content="index, follow, max-image-preview:large">', false);

        // 2. Filtered Category (should preserve clean canonical and add noindex)
        $resFiltered = $this->get(route('products.index', ['category' => 'fashion', 'min_price' => '100', 'sort' => 'price_desc']));
        $resFiltered->assertOk();
        $resFiltered->assertSee('<link rel="canonical" href="' . route('products.index', ['category' => 'fashion']) . '">', false);
        $resFiltered->assertSee('<meta name="robots" content="noindex, follow">', false);

        // 3. Paginated Catalog (canonical points to clean base catalog, robots is noindex)
        $resPaginated = $this->get('/products?page=2');
        $resPaginated->assertOk();
        $resPaginated->assertSee('<link rel="canonical" href="' . route('products.index') . '">', false);
        $resPaginated->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_seller_show_page_seo_head(): void
    {
        $sellerUser = User::factory()->create();
        $seller = SellerProfile::factory()->create([
            'user_id' => $sellerUser->id,
            'store_name' => 'Vintage Hub',
            'store_slug' => 'vintage-hub',
            'status' => SellerStatus::Approved,
        ]);

        $res = $this->get(route('sellers.show', ['slug' => 'vintage-hub']));
        $res->assertOk();
        $res->assertSee("<title>Vintage Hub - " . brand_name() . "</title>", false);
        $res->assertSee('<link rel="canonical" href="' . route('sellers.show', ['slug' => 'vintage-hub']) . '">', false);
        $res->assertSee('<meta name="robots" content="index, follow, max-image-preview:large">', false);
    }
}
