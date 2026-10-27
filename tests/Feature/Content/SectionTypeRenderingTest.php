<?php

use App\Content\DTOs\ResolvedItemData;
use App\Content\DTOs\ResolvedLinkData;
use App\Content\DTOs\ResolvedMediaData;
use App\Content\DTOs\ResolvedSectionData;
use App\Content\Models\ContentPage;
use App\Models\Admin;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function makeTestMedia(
    int $id = 1,
    string $url = 'https://example.com/media.png',
    ?string $webpUrl = 'https://example.com/media.webp',
    int $width = 1200,
    int $height = 600,
    string $alt = 'Test Alt Image'
): ResolvedMediaData {
    return new ResolvedMediaData(
        id: $id,
        url: $url,
        webpUrl: $webpUrl,
        width: $width,
        height: $height,
        alt: $alt,
        variants: $webpUrl ? ['webp' => $webpUrl] : []
    );
}

function makeTestLink(
    string $url = 'https://example.com/target-link',
    bool $isExternal = false
): ResolvedLinkData {
    return new ResolvedLinkData(
        url: $url,
        isValid: true,
        isExternal: $isExternal,
        target: $isExternal ? '_blank' : '_self',
        rel: $isExternal ? 'noopener noreferrer' : null,
    );
}

test('hero_carousel renders heading, link, eager/lazy loading, dimensions, webp source, and mobile fallback', function () {
    $desktopMedia = makeTestMedia(1, 'https://example.com/hero-desktop.png', 'https://example.com/hero-desktop.webp', 1920, 600, 'Hero Desktop Banner');

    // 1. Eager hero carousel item with mobile fallback to desktop
    $eagerSection = new ResolvedSectionData(
        id: 101,
        type: 'hero_carousel',
        position: 1,
        visibility: 'all',
        settings: [
            'autoplay' => true,
            'interval_ms' => 5000,
            'show_dots' => true,
            'show_arrows' => true,
        ],
        items: [
            new ResolvedItemData(
                id: 1,
                desktopMedia: $desktopMedia,
                mobileMedia: null, // triggers mobile fallback to desktopMedia
                link: makeTestLink('https://example.com/category/deals'),
                settings: [
                    'heading' => 'Summer Mega Fest 2026',
                    'subheading' => 'Up to 70% off on all trending styles',
                    'badge_text' => 'HOT DEAL',
                ]
            ),
        ],
        isEager: true
    );

    $eagerHtml = Blade::render('<x-dynamic-component :component="\'content.sections.hero-carousel\'" :section="$section" />', ['section' => $eagerSection]);

    // Assert heading and link
    expect($eagerHtml)->toContain('Summer Mega Fest 2026')
        ->and($eagerHtml)->toContain('href="https://example.com/category/deals"')
        // Assert img dimensions & alt
        ->and($eagerHtml)->toContain('alt="Hero Desktop Banner"')
        ->and($eagerHtml)->toContain('width="1920"')
        ->and($eagerHtml)->toContain('height="600"')
        // Assert webp source
        ->and($eagerHtml)->toContain('<source srcset="https://example.com/hero-desktop.webp" type="image/webp">')
        // Assert eager loading
        ->and($eagerHtml)->toContain('loading="eager"')
        ->and($eagerHtml)->toContain('decoding="sync"')
        // Assert mobile container falls back to desktop image url
        ->and($eagerHtml)->toContain('src="https://example.com/hero-desktop.png"');

    // 2. Non-eager section renders lazy loading
    $lazySection = new ResolvedSectionData(
        id: 102,
        type: 'hero_carousel',
        position: 2,
        visibility: 'all',
        settings: [],
        items: [
            new ResolvedItemData(
                id: 2,
                desktopMedia: $desktopMedia,
                mobileMedia: null,
                link: makeTestLink(),
                settings: ['heading' => 'Lazy Carousel Slide']
            ),
        ],
        isEager: false
    );

    $lazyHtml = Blade::render('<x-dynamic-component :component="\'content.sections.hero-carousel\'" :section="$section" />', ['section' => $lazySection]);
    expect($lazyHtml)->toContain('loading="lazy"')
        ->and($lazyHtml)->toContain('decoding="async"');
});

test('single_banner renders headline, link, image dimensions, webp, and mobile image', function () {
    $desktopMedia = makeTestMedia(2, 'https://example.com/banner-desktop.png', 'https://example.com/banner-desktop.webp', 1200, 300, 'Single Banner Desktop');
    $mobileMedia = makeTestMedia(3, 'https://example.com/banner-mobile.png', 'https://example.com/banner-mobile.webp', 600, 400, 'Single Banner Mobile');

    $section = new ResolvedSectionData(
        id: 103,
        type: 'single_banner',
        position: 1,
        visibility: 'all',
        settings: ['layout' => 'boxed', 'height_preset' => 'md'],
        items: [
            new ResolvedItemData(
                id: 3,
                desktopMedia: $desktopMedia,
                mobileMedia: $mobileMedia,
                link: makeTestLink('https://example.com/sale'),
                settings: [
                    'headline' => 'Exclusive Weekend Specials',
                    'subheadline' => 'Limited inventory available',
                    'overlay_opacity' => 40,
                ]
            ),
        ],
        isEager: true
    );

    $html = Blade::render('<x-dynamic-component :component="\'content.sections.single-banner\'" :section="$section" />', ['section' => $section]);

    expect($html)->toContain('Exclusive Weekend Specials')
        ->and($html)->toContain('href="https://example.com/sale"')
        ->and($html)->toContain('alt="Single Banner Desktop"')
        ->and($html)->toContain('width="1200"')
        ->and($html)->toContain('height="300"')
        ->and($html)->toContain('alt="Single Banner Mobile"')
        ->and($html)->toContain('width="600"')
        ->and($html)->toContain('height="400"')
        ->and($html)->toContain('srcset="https://example.com/banner-desktop.webp"')
        ->and($html)->toContain('srcset="https://example.com/banner-mobile.webp"');
});

test('wide_banner renders heading, badge, link, and background color', function () {
    $desktopMedia = makeTestMedia(4, 'https://example.com/wide.png', 'https://example.com/wide.webp', 1600, 250, 'Wide Shipping Banner');

    $section = new ResolvedSectionData(
        id: 104,
        type: 'wide_banner',
        position: 1,
        visibility: 'all',
        settings: ['background_color' => '#0f172a', 'text_alignment' => 'center'],
        items: [
            new ResolvedItemData(
                id: 4,
                desktopMedia: $desktopMedia,
                mobileMedia: null,
                link: makeTestLink('https://example.com/free-shipping'),
                settings: [
                    'heading' => 'Free Express Shipping Across India',
                    'badge_text' => 'SPECIAL PERK',
                ]
            ),
        ],
        isEager: false
    );

    $html = Blade::render('<x-dynamic-component :component="\'content.sections.wide-banner\'" :section="$section" />', ['section' => $section]);

    expect($html)->toContain('Free Express Shipping Across India')
        ->and($html)->toContain('SPECIAL PERK')
        ->and($html)->toContain('background-color: #0f172a;')
        ->and($html)->toContain('href="https://example.com/free-shipping"')
        ->and($html)->toContain('alt="Wide Shipping Banner"');
});

test('image_tile_grid renders section heading, tile labels, links, and image dimensions', function () {
    $tileMedia = makeTestMedia(5, 'https://example.com/tile1.png', 'https://example.com/tile1.webp', 400, 400, 'Tile 1 Alt');

    $section = new ResolvedSectionData(
        id: 105,
        type: 'image_tile_grid',
        position: 1,
        visibility: 'all',
        settings: [
            'heading' => 'Shop By Essential Department',
            'columns' => 4,
            'aspect_ratio' => '1:1',
        ],
        items: [
            new ResolvedItemData(
                id: 5,
                desktopMedia: $tileMedia,
                mobileMedia: null,
                link: makeTestLink('https://example.com/dept/electronics'),
                settings: [
                    'label' => 'Electronics & Accessories',
                    'sublabel' => 'Starting at ₹499',
                ]
            ),
        ]
    );

    $html = Blade::render('<x-dynamic-component :component="\'content.sections.image-tile-grid\'" :section="$section" />', ['section' => $section]);

    expect($html)->toContain('Shop By Essential Department')
        ->and($html)->toContain('Electronics &amp; Accessories')
        ->and($html)->toContain('Starting at ₹499')
        ->and($html)->toContain('href="https://example.com/dept/electronics"')
        ->and($html)->toContain('alt="Tile 1 Alt"')
        ->and($html)->toContain('width="400"')
        ->and($html)->toContain('height="400"');
});

test('category_nav renders section heading, circular styles, links, and media images', function () {
    $catMedia = makeTestMedia(6, 'https://example.com/cat.png', 'https://example.com/cat.webp', 128, 128, 'Fashion Category Icon');

    $section = new ResolvedSectionData(
        id: 106,
        type: 'category_nav',
        position: 1,
        visibility: 'all',
        settings: [
            'heading' => 'Browse Popular Categories',
            'display_style' => 'circular',
            'show_labels' => true,
        ],
        items: [
            new ResolvedItemData(
                id: 6,
                desktopMedia: $catMedia,
                mobileMedia: null,
                link: makeTestLink('https://example.com/categories/fashion'),
                settings: [
                    'custom_label' => 'Men & Women Fashion',
                ]
            ),
        ]
    );

    $html = Blade::render('<x-dynamic-component :component="\'content.sections.category-nav\'" :section="$section" />', ['section' => $section]);

    expect($html)->toContain('Browse Popular Categories')
        ->and($html)->toContain('Men &amp; Women Fashion')
        ->and($html)->toContain('href="https://example.com/categories/fashion"')
        ->and($html)->toContain('alt="Fashion Category Icon"')
        ->and($html)->toContain('src="https://example.com/cat.png"');
});

test('promo_card_carousel renders carousel header, badge, deal title, prices, and media images', function () {
    $dealMedia = makeTestMedia(7, 'https://example.com/deal.png', 'https://example.com/deal.webp', 300, 300, 'Deal Watch Alt');

    $section = new ResolvedSectionData(
        id: 107,
        type: 'promo_card_carousel',
        position: 1,
        visibility: 'all',
        settings: [
            'heading' => 'Lightning Deals of the Day',
            'subheading' => 'Updated every hour with top discounts',
            'badge_text' => 'FLASH 24H',
        ],
        items: [
            new ResolvedItemData(
                id: 7,
                desktopMedia: $dealMedia,
                mobileMedia: null,
                link: makeTestLink('https://example.com/products/smart-watch'),
                settings: [
                    'override_title' => 'Titanium Smart Watch Pro',
                    'override_price' => '2499',
                    'live_price' => 249900,
                    'live_mrp' => 499900,
                    'live_discount' => 50,
                ]
            ),
        ]
    );

    $html = Blade::render('<x-dynamic-component :component="\'content.sections.promo-card-carousel\'" :section="$section" />', ['section' => $section]);

    expect($html)->toContain('Lightning Deals of the Day')
        ->and($html)->toContain('FLASH 24H')
        ->and($html)->toContain('Titanium Smart Watch Pro')
        ->and($html)->toContain('href="https://example.com/products/smart-watch"')
        ->and($html)->toContain('alt="Deal Watch Alt"');
});

test('brand_tiles renders brand heading, brand names, brand links, and logos', function () {
    $brandLogo = makeTestMedia(8, 'https://example.com/nike.png', 'https://example.com/nike.webp', 200, 80, 'Nike Official Logo');

    $section = new ResolvedSectionData(
        id: 108,
        type: 'brand_tiles',
        position: 1,
        visibility: 'all',
        settings: [
            'heading' => 'Top Featured Brands',
            'columns' => 6,
        ],
        items: [
            new ResolvedItemData(
                id: 8,
                desktopMedia: $brandLogo,
                mobileMedia: null,
                link: makeTestLink('https://example.com/brands/nike'),
                settings: [
                    'custom_name' => 'Nike',
                ]
            ),
        ]
    );

    $html = Blade::render('<x-dynamic-component :component="\'content.sections.brand-tiles\'" :section="$section" />', ['section' => $section]);

    expect($html)->toContain('Top Featured Brands')
        ->and($html)->toContain('href="https://example.com/brands/nike"')
        ->and($html)->toContain('alt="Nike Official Logo"')
        ->and($html)->toContain('width="200"')
        ->and($html)->toContain('height="80"');
});

test('countdown renders headline, subheadline, badge, and end time target', function () {
    $endTime = Carbon::now()->addHours(12)->toIso8601String();

    $section = new ResolvedSectionData(
        id: 109,
        type: 'countdown',
        position: 1,
        visibility: 'all',
        settings: [
            'headline' => 'Super Midnight Rush Sale',
            'subheadline' => 'Grab exclusive drops before midnight bells ring',
            'badge' => 'ENDS TONIGHT',
            'end_time' => $endTime,
            'cta_label' => 'Shop Now',
            'cta_link_url' => 'https://example.com/sale',
        ],
        items: []
    );

    $html = Blade::render('<x-dynamic-component :component="\'content.sections.countdown\'" :section="$section" />', ['section' => $section]);

    expect($html)->toContain('Super Midnight Rush Sale')
        ->and($html)->toContain('Grab exclusive drops before midnight bells ring')
        ->and($html)->toContain('ENDS TONIGHT')
        ->and($html)->toContain($endTime);
});

test('rich_text renders markdown sanitized HTML content', function () {
    $section = new ResolvedSectionData(
        id: 110,
        type: 'rich_text',
        position: 1,
        visibility: 'all',
        settings: [
            'container_width' => 'standard',
            'rendered_html' => '<h2>Authentic Marketplace Quality</h2><p>Every product is verified by our quality control specialists.</p>',
        ],
        items: []
    );

    $html = Blade::render('<x-dynamic-component :component="\'content.sections.rich-text\'" :section="$section" />', ['section' => $section]);

    expect($html)->toContain('<h2>Authentic Marketplace Quality</h2>')
        ->and($html)->toContain('<p>Every product is verified by our quality control specialists.</p>')
        ->and($html)->toContain('max-w-5xl');
});

test('HTTP GET / renders the seeded storefront home page with sections and images', function () {
    Artisan::call('db:seed', ['--class' => 'ContentSeeder']);

    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('data-section-type="hero_carousel"', false)
        ->assertSee('Summer Style Refresh')
        ->assertSee('<picture>', false)
        ->assertSee('data-section-type="countdown"', false)
        ->assertSee('Weekend Flash Rush')
        ->assertSee('data-section-type="promo_card_carousel"', false)
        ->assertSee('Men &amp; Women Wear', false)
        ->assertSee('data-section-type="brand_tiles"', false)
        ->assertSee('Nike brand logo');
});

test('HTTP GET /pages/about-us renders seeded static page with markdown content', function () {
    Artisan::call('db:seed', ['--class' => 'ContentSeeder']);

    $response = $this->get('/pages/about-us');

    $response->assertOk()
        ->assertSee('About Our Marketplace')
        ->assertSee('Our Mission')
        ->assertSee('Verified Sellers')
        ->assertSee('Customer First Support');
});

test('HTTP GET signed admin preview URL for seeded home page renders toolbar, images, and noindex header', function () {
    Artisan::call('permissions:sync');
    Artisan::call('db:seed', ['--class' => 'ContentSeeder']);

    $admin = Admin::factory()->create();
    $admin->givePermissionTo(AdminPermissions::PAGES_VIEW);

    $homePage = ContentPage::where('slug', 'home')->firstOrFail();

    $signedUrl = URL::temporarySignedRoute(
        'admin.pages.preview',
        now()->addHour(),
        ['page' => $homePage, 'device' => 'desktop']
    );

    $response = $this->actingAs($admin, 'admin')->get($signedUrl);

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('PREVIEW MODE')
        ->assertSee('Back to Builder')
        ->assertSee('Summer Style Refresh')
        ->assertSee('<picture>', false)
        ->assertSee('data-section-type="hero_carousel"', false)
        ->assertSee('data-section-type="brand_tiles"', false);
});
