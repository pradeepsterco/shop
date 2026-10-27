<?php

namespace Database\Seeders;

use App\Catalog\Contracts\CatalogReadInterface;
use App\Content\Enums\LinkType;
use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Enums\SectionVisibility;
use App\Content\Models\ContentMedia;
use App\Content\Models\ContentPage;
use App\Content\Models\ContentSection;
use App\Content\Models\ContentSectionItem;
use App\Content\Services\PagePublishService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class ContentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(CatalogReadInterface $catalog, PagePublishService $publisher): void
    {
        $disk = 'public';
        Storage::disk($disk)->makeDirectory('content-media');

        // 1. Generate Placeholder Media Assets
        $hero1 = $this->createPlaceholderImage(
            disk: $disk,
            name: 'hero-summer-collection',
            width: 1920,
            height: 600,
            bgColor: [79, 70, 229], // Indigo 600
            textColor: [255, 255, 255],
            text: 'SUMMER COLLECTION 2026 - UP TO 50% OFF',
            altText: 'Summer collection hero banner'
        );

        $hero1Mobile = $this->createPlaceholderImage(
            disk: $disk,
            name: 'hero-summer-collection-mobile',
            width: 768,
            height: 800,
            bgColor: [67, 56, 202], // Indigo 700
            textColor: [255, 255, 255],
            text: 'SUMMER COLLECTION 2026',
            altText: 'Summer collection mobile hero banner'
        );

        $hero2 = $this->createPlaceholderImage(
            disk: $disk,
            name: 'hero-trending-gear',
            width: 1920,
            height: 600,
            bgColor: [5, 150, 105], // Emerald 600
            textColor: [255, 255, 255],
            text: 'TRENDING TECH & ESSENTIALS - EXPLORE NOW',
            altText: 'Trending tech hero banner'
        );

        $promo1 = $this->createPlaceholderImage(
            disk: $disk,
            name: 'promo-card-fashion',
            width: 600,
            height: 400,
            bgColor: [217, 119, 6], // Amber 600
            textColor: [255, 255, 255],
            text: 'MEN & WOMEN FASHION',
            altText: 'Fashion promotional card'
        );

        $promo2 = $this->createPlaceholderImage(
            disk: $disk,
            name: 'promo-card-footwear',
            width: 600,
            height: 400,
            bgColor: [225, 29, 72], // Rose 600
            textColor: [255, 255, 255],
            text: 'SNEAKERS & RUNNING SHOES',
            altText: 'Footwear promotional card'
        );

        $promo3 = $this->createPlaceholderImage(
            disk: $disk,
            name: 'promo-card-gadgets',
            width: 600,
            height: 400,
            bgColor: [14, 165, 233], // Sky 500
            textColor: [255, 255, 255],
            text: 'SMART DEVICES & ACCESSORIES',
            altText: 'Smart devices promotional card'
        );

        $promo4 = $this->createPlaceholderImage(
            disk: $disk,
            name: 'promo-card-deals',
            width: 600,
            height: 400,
            bgColor: [139, 92, 246], // Violet 500
            textColor: [255, 255, 255],
            text: 'SPECIAL CLEARANCE DEALS',
            altText: 'Special clearance deals card'
        );

        $wideBannerMedia = $this->createPlaceholderImage(
            disk: $disk,
            name: 'wide-banner-shipping',
            width: 1600,
            height: 260,
            bgColor: [15, 23, 42], // Slate 900
            textColor: [248, 250, 252],
            text: 'FREE EXPRESS SHIPPING ON ALL ORDERS OVER INR 499 - LIMITED TIME ONLY',
            altText: 'Free shipping promotional banner'
        );

        $brandMedia1 = $this->createPlaceholderImage(
            disk: $disk,
            name: 'brand-nike',
            width: 320,
            height: 160,
            bgColor: [241, 245, 249],
            textColor: [15, 23, 42],
            text: 'NIKE',
            altText: 'Nike brand logo'
        );

        $brandMedia2 = $this->createPlaceholderImage(
            disk: $disk,
            name: 'brand-puma',
            width: 320,
            height: 160,
            bgColor: [241, 245, 249],
            textColor: [15, 23, 42],
            text: 'PUMA',
            altText: 'Puma brand logo'
        );

        $brandMedia3 = $this->createPlaceholderImage(
            disk: $disk,
            name: 'brand-apple',
            width: 320,
            height: 160,
            bgColor: [241, 245, 249],
            textColor: [15, 23, 42],
            text: 'APPLE',
            altText: 'Apple brand logo'
        );

        $brandMedia4 = $this->createPlaceholderImage(
            disk: $disk,
            name: 'brand-samsung',
            width: 320,
            height: 160,
            bgColor: [241, 245, 249],
            textColor: [15, 23, 42],
            text: 'SAMSUNG',
            altText: 'Samsung brand logo'
        );

        // Fetch catalog samples for realistic links
        $fashionCat = $catalog->findCategory('fashion');
        $nikeBrand = $catalog->findBrand('nike');
        $pumaBrand = $catalog->findBrand('puma');
        $appleBrand = $catalog->findBrand('apple');
        $samsungBrand = $catalog->findBrand('samsung');

        // 2. Seed Home Page
        /** @var ContentPage $homePage */
        $homePage = ContentPage::firstOrCreate(
            ['slug' => 'home'],
            [
                'title' => 'Home',
                'type' => PageType::Home,
                'status' => PageStatus::Draft,
                'seo_title' => 'Shop Premium Products Online - Marketplace',
                'seo_description' => 'Explore thousands of curated products from top sellers across India. Enjoy fast shipping and secure payments.',
            ]
        );

        // Delete any old draft sections for clean idempotent re-seed
        $homePage->sections()->delete();

        // Section 1: Hero Carousel
        $heroSection = ContentSection::create([
            'page_id' => $homePage->id,
            'type' => 'hero_carousel',
            'schema_version' => 1,
            'position' => 1,
            'visibility' => SectionVisibility::All,
            'settings' => [
                'autoplay' => true,
                'autoplay_interval_ms' => 5000,
                'height_desktop' => 'medium',
                'full_width' => true,
            ],
        ]);

        ContentSectionItem::create([
            'section_id' => $heroSection->id,
            'position' => 1,
            'desktop_media_id' => $hero1->id,
            'mobile_media_id' => $hero1Mobile->id,
            'link_type' => $fashionCat ? LinkType::Category : LinkType::Search,
            'link_id' => $fashionCat ? (string) $fashionCat['id'] : null,
            'link_url' => $fashionCat ? null : 't-shirt',
            'settings' => [
                'headline' => 'Summer Style Refresh',
                'subheading' => 'Elevate your wardrobe with the latest seasonal drops.',
                'badge_text' => 'NEW SEASON',
                'cta_label' => 'Shop Collection',
                'overlay_opacity' => 20,
            ],
        ]);

        ContentSectionItem::create([
            'section_id' => $heroSection->id,
            'position' => 2,
            'desktop_media_id' => $hero2->id,
            'mobile_media_id' => null,
            'link_type' => LinkType::Search,
            'link_url' => 'deals',
            'settings' => [
                'headline' => 'Smart Tech & Gadgets',
                'subheading' => 'Top-tier devices engineered for peak performance.',
                'badge_text' => 'HOT DEALS',
                'cta_label' => 'Explore Tech',
                'overlay_opacity' => 20,
            ],
        ]);

        // Section 2: Category Nav
        $categorySection = ContentSection::create([
            'page_id' => $homePage->id,
            'type' => 'category_nav',
            'schema_version' => 1,
            'position' => 2,
            'visibility' => SectionVisibility::All,
            'settings' => [
                'columns' => 6,
                'show_labels' => true,
            ],
        ]);

        $activeCategories = $catalog->getActiveCategories();
        $catPos = 1;
        foreach (array_slice($activeCategories, 0, 6) as $cat) {
            ContentSectionItem::create([
                'section_id' => $categorySection->id,
                'position' => $catPos++,
                'desktop_media_id' => null,
                'mobile_media_id' => null,
                'link_type' => LinkType::Category,
                'link_id' => (string) $cat['id'],
                'settings' => [
                    'label' => $cat['name'],
                    'icon' => 'sparkles',
                ],
            ]);
        }

        // Section 3: Countdown Flash Sale
        $countdownSection = ContentSection::create([
            'page_id' => $homePage->id,
            'type' => 'countdown',
            'schema_version' => 1,
            'position' => 3,
            'visibility' => SectionVisibility::All,
            'settings' => [
                'end_time' => Carbon::now()->addDays(3)->toIso8601String(),
                'expired_behavior' => 'hide',
                'headline' => 'Weekend Flash Rush',
                'subheading' => 'Grab handpicked favorites at unbelievable discounts before the clock runs out!',
                'bg_color' => '#fef2f2',
                'cta_label' => 'Shop Flash Deals',
                'cta_link_type' => 'search',
                'cta_link_url' => 'sale',
            ],
        ]);

        // Section 4: Promo Card Carousel
        $promoSection = ContentSection::create([
            'page_id' => $homePage->id,
            'type' => 'promo_card_carousel',
            'schema_version' => 1,
            'position' => 4,
            'visibility' => SectionVisibility::All,
            'settings' => [
                'cards_visible' => 4,
            ],
        ]);

        $promoData = [
            ['media' => $promo1, 'title' => 'Men & Women Wear', 'sub' => 'Starting at ₹299', 'tag' => 'FASHION'],
            ['media' => $promo2, 'title' => 'Sneakers & Running', 'sub' => 'Flat 40% Off', 'tag' => 'FOOTWEAR'],
            ['media' => $promo3, 'title' => 'Smart Devices', 'sub' => 'Best in Tech', 'tag' => 'GADGETS'],
            ['media' => $promo4, 'title' => 'Clearance Sale', 'sub' => 'Extra 15% on Checkout', 'tag' => 'SAVINGS'],
        ];

        foreach ($promoData as $idx => $p) {
            ContentSectionItem::create([
                'section_id' => $promoSection->id,
                'position' => $idx + 1,
                'desktop_media_id' => $p['media']->id,
                'mobile_media_id' => null,
                'link_type' => LinkType::Search,
                'link_url' => strtolower($p['tag']),
                'settings' => [
                    'title' => $p['title'],
                    'subtitle' => $p['sub'],
                    'tag' => $p['tag'],
                ],
            ]);
        }

        // Section 5: Wide Banner
        $wideSection = ContentSection::create([
            'page_id' => $homePage->id,
            'type' => 'wide_banner',
            'schema_version' => 1,
            'position' => 5,
            'visibility' => SectionVisibility::All,
            'settings' => [
                'full_width' => true,
            ],
        ]);

        ContentSectionItem::create([
            'section_id' => $wideSection->id,
            'position' => 1,
            'desktop_media_id' => $wideBannerMedia->id,
            'mobile_media_id' => null,
            'link_type' => LinkType::Search,
            'link_url' => 'express',
            'settings' => [
                'alt_text' => 'Express Free Delivery Nationwide',
            ],
        ]);

        // Section 6: Brand Tiles
        $brandSection = ContentSection::create([
            'page_id' => $homePage->id,
            'type' => 'brand_tiles',
            'schema_version' => 1,
            'position' => 6,
            'visibility' => SectionVisibility::All,
            'settings' => [
                'columns' => 4,
                'grayscale' => false,
            ],
        ]);

        $brandList = [
            ['brand' => $nikeBrand, 'media' => $brandMedia1],
            ['brand' => $pumaBrand, 'media' => $brandMedia2],
            ['brand' => $appleBrand, 'media' => $brandMedia3],
            ['brand' => $samsungBrand, 'media' => $brandMedia4],
        ];

        foreach ($brandList as $bIdx => $bInfo) {
            ContentSectionItem::create([
                'section_id' => $brandSection->id,
                'position' => $bIdx + 1,
                'desktop_media_id' => $bInfo['media']->id,
                'mobile_media_id' => null,
                'link_type' => $bInfo['brand'] ? LinkType::Brand : LinkType::Search,
                'link_id' => $bInfo['brand'] ? (string) $bInfo['brand']['id'] : null,
                'link_url' => $bInfo['brand'] ? null : 'top-brands',
                'settings' => [
                    'brand_name' => $bInfo['brand']['name'] ?? 'Brand',
                ],
            ]);
        }

        // Publish Home Page
        $publisher->publish($homePage);

        // 3. Seed Static Pages
        $staticPages = [
            [
                'title' => 'About Us',
                'slug' => 'about-us',
                'seo_title' => 'About Us - Our Story & Mission',
                'seo_description' => 'Learn about our marketplace story, dedicated sellers, and commitment to delivering quality products.',
                'content' => <<<'MARKDOWN'
# About Our Marketplace

Welcome to the next generation of online shopping! We connect passionate independent sellers, trusted artisans, and leading manufacturers directly with discerning customers across the country.

### Our Mission
Our mission is simple: **empower sellers to thrive** while offering shoppers an authentic, enjoyable, and safe marketplace experience. Every transaction is protected with our end-to-end verified guarantee.

### Why Choose Us?
- **Verified Sellers**: Every seller undergoes a rigorous qualification process before listing items.
- **Fair Pricing**: We ensure transparent pricing with no hidden checkout surprises.
- **Fast & Reliable Delivery**: Partnered with premier logistics networks to reach your doorstep quickly.
- **Customer First Support**: Dedicated customer care team available to assist with any inquiry.
MARKDOWN
            ],
            [
                'title' => 'Terms & Conditions',
                'slug' => 'terms-and-conditions',
                'seo_title' => 'Terms & Conditions - User Agreement',
                'seo_description' => 'Read our terms of service, acceptable use guidelines, and platform policies.',
                'content' => <<<'MARKDOWN'
# Terms and Conditions

Please read these Terms and Conditions carefully before using our platform. By accessing or using our marketplace, you agree to be bound by these terms.

### 1. User Accounts
When you create an account with us, you must provide accurate, complete, and current information at all times. Failure to do so constitutes a breach of the Terms.

### 2. Purchases and Payments
All purchases through our website are subject to product availability. We reserve the right to refuse or cancel any order for reasons including item unavailability, errors in product description or pricing, or suspected fraud.

### 3. Seller Obligations
Sellers on this platform operate as independent businesses and are strictly required to adhere to our quality guidelines, accurate stock reporting, and statutory consumer protection rules.

### 4. Returns & Refunds
Our marketplace provides a standard return window on eligible items. Return requests must be initiated via the customer dashboard within the designated return policy period.
MARKDOWN
            ],
            [
                'title' => 'Privacy Policy',
                'slug' => 'privacy-policy',
                'seo_title' => 'Privacy Policy - How We Protect Your Data',
                'seo_description' => 'Understand how we collect, handle, and protect your personal data and privacy.',
                'content' => <<<'MARKDOWN'
# Privacy Policy

Your privacy is paramount to us. This privacy policy explains what personal information we collect and how we use, disclose, and safeguard that information.

### 1. Information We Collect
- **Account Details**: Name, email address, contact numbers, and billing addresses.
- **Transaction Data**: Details regarding orders, payments, delivery addresses, and refunds.
- **Device & Usage Info**: IP address, browser type, cookies, and page interaction timestamps.

### 2. How We Use Your Information
We utilize the collected information to process transactions, maintain customer security, prevent fraudulent activity, improve our catalog recommendations, and fulfill legal compliance obligations.

### 3. Data Protection
We employ industry-standard encryption protocols (TLS/SSL) for all sensitive data transfers. We do not sell your personal data to third-party advertisers.
MARKDOWN
            ],
            [
                'title' => 'Contact Us',
                'slug' => 'contact-us',
                'seo_title' => 'Contact Us - Customer Care & Support',
                'seo_description' => 'Get in touch with our customer service team. We are here to help 24/7.',
                'content' => <<<'MARKDOWN'
# Contact Customer Support

Have a question about your order, tracking, or platform features? Our dedicated support team is here to assist you.

### Support Channels
- **Email Support**: support@example.com (Response within 24 hours)
- **Helpline**: +91 (800) 123-4567 (Mon - Sat, 9:00 AM - 7:00 PM IST)
- **Headquarters**: 100 Tech Boulevard, Outer Ring Road, Bengaluru, Karnataka, India

### Seller Inquiries
Interested in becoming a verified merchant? Visit our Seller Registration Portal to get started in minutes.
MARKDOWN
            ],
        ];

        foreach ($staticPages as $pageData) {
            /** @var ContentPage $page */
            $page = ContentPage::firstOrCreate(
                ['slug' => $pageData['slug']],
                [
                    'title' => $pageData['title'],
                    'type' => PageType::Static,
                    'status' => PageStatus::Draft,
                    'seo_title' => $pageData['seo_title'],
                    'seo_description' => $pageData['seo_description'],
                ]
            );

            $page->sections()->delete();

            $section = ContentSection::create([
                'page_id' => $page->id,
                'type' => 'rich_text',
                'schema_version' => 1,
                'position' => 1,
                'visibility' => SectionVisibility::All,
                'settings' => [
                    'content_markdown' => $pageData['content'],
                    'max_width' => '4xl',
                ],
            ]);

            $publisher->publish($page);
        }
    }

    /**
     * Helper to create a truecolor GD image with text, saving both PNG and WebP files.
     *
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     * @param  array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}  $bgColor
     * @param  array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}  $textColor
     */
    private function createPlaceholderImage(
        string $disk,
        string $name,
        int $width,
        int $height,
        array $bgColor,
        array $textColor,
        string $text,
        string $altText
    ): ContentMedia {
        $folder = 'content-media';
        $pngFilename = "{$name}.png";
        $webpFilename = "{$name}.webp";
        $pngPath = "{$folder}/{$pngFilename}";
        $webpPath = "{$folder}/{$webpFilename}";

        $fullPngPath = Storage::disk($disk)->path($pngPath);
        $fullWebpPath = Storage::disk($disk)->path($webpPath);

        // Ensure target folder exists
        $dir = dirname($fullPngPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Generate GD image
        $image = imagecreatetruecolor($width, $height);
        if (! $image) {
            throw new \RuntimeException('Failed to initialize GD image.');
        }

        $bg = imagecolorallocate($image, $bgColor[0], $bgColor[1], $bgColor[2]);
        $fg = imagecolorallocate($image, $textColor[0], $textColor[1], $textColor[2]);

        if ($bg === false) {
            $bg = 0;
        }
        if ($fg === false) {
            $fg = 16777215;
        }

        imagefill($image, 0, 0, $bg);

        // Calculate text positioning (using built-in font 5)
        $font = 5;
        $fontWidth = imagefontwidth($font);
        $fontHeight = imagefontheight($font);
        $textLen = strlen($text);
        $x = (int) max(10, ($width - ($textLen * $fontWidth)) / 2);
        $y = (int) max(10, ($height - $fontHeight) / 2);

        imagestring($image, $font, $x, $y, $text, $fg);

        // Save PNG
        imagepng($image, $fullPngPath);

        // Save WebP if available
        $variants = [];
        if (function_exists('imagewebp')) {
            imagewebp($image, $fullWebpPath, 85);
            $variants['webp'] = $webpPath;
        }

        imagedestroy($image);

        $fileSize = file_exists($fullPngPath) ? (int) filesize($fullPngPath) : 1024;

        return ContentMedia::firstOrCreate(
            ['path' => $pngPath],
            [
                'filename' => $pngFilename,
                'disk' => $disk,
                'variants' => ! empty($variants) ? $variants : null,
                'mime_type' => 'image/png',
                'size_bytes' => $fileSize,
                'width' => $width,
                'height' => $height,
                'alt_text' => $altText,
            ]
        );
    }
}
