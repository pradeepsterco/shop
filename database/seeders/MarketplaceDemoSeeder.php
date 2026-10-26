<?php

namespace Database\Seeders;

use App\Checkout\Models\CheckoutIntent;
use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Brand;
use App\Models\CustomerAddress;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Orders\DTOs\PlaceOrderData;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\OrderService;
use App\Services\ProductBuyBoxService;
use App\Services\UpidGenerator;
use App\Services\VariantKeyNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MarketplaceDemoSeeder extends Seeder
{
    /**
     * @var array<string, mixed>
     */
    public array $stats = [];

    public function run(): void
    {
        if (! app()->isLocal()) {
            throw new \RuntimeException('MarketplaceDemoSeeder can only be executed in a local development environment.');
        }

        Mail::fake();

        $this->ensurePlaceholderImages();

        $sellers = $this->seedSellers();
        $products = $this->seedProductsAndListings($sellers);
        $customers = $this->seedCustomers();
        $orderStats = $this->seedOrders($customers, $sellers);

        $this->compileStats($sellers, $products, $customers, $orderStats);
    }

    /**
     * Ensure SVG placeholder images exist in public storage.
     */
    protected function ensurePlaceholderImages(): void
    {
        $configs = [
            'board-game' => ['#e0f2fe', '#0369a1', 'Board Game'],
            'action-figure' => ['#fef3c7', '#b45309', 'Action Figure'],
            'building-blocks' => ['#fee2e2', '#b91c1c', 'Building Blocks'],
            't-shirt' => ['#f3e8ff', '#7e22ce', 'Fashion T-Shirt'],
            'smartphone' => ['#e0e7ff', '#4338ca', 'Smartphone'],
        ];

        foreach ($configs as $key => [$bg, $fg, $label]) {
            $path = "products/{$key}.svg";
            if (! Storage::disk('public')->exists($path)) {
                $svg = sprintf(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600" viewBox="0 0 600 600">'
                    .'<rect width="600" height="600" fill="%s" rx="16"/>'
                    .'<circle cx="300" cy="240" r="100" fill="%s" opacity="0.15"/>'
                    .'<text x="300" y="255" font-family="system-ui, -apple-system, sans-serif" font-size="44" font-weight="bold" fill="%s" text-anchor="middle">%s</text>'
                    .'<text x="300" y="380" font-family="system-ui, -apple-system, sans-serif" font-size="22" fill="%s" text-anchor="middle" opacity="0.75">Khilauna Marketplace</text>'
                    .'</svg>',
                    $bg, $fg, $fg, htmlspecialchars($label), $fg
                );
                Storage::disk('public')->put($path, $svg);
            }
        }
    }

    /**
     * Seed 8 sellers with different specialities and Indian states.
     *
     * @return array<int, SellerProfile>
     */
    protected function seedSellers(): array
    {
        $sellersConfig = [
            1 => [
                'name' => 'Rajesh Sharma',
                'email' => 'seller1@example.com',
                'store_name' => 'Delhi Toy Kingdom',
                'store_slug' => 'delhi-toy-kingdom',
                'business_type' => 'company',
                'description' => 'Premier retailer for classic and modern toys, board games, and puzzles.',
                'support_phone' => '9811001100',
                'pickup_line1' => 'Plot 14, Okhla Industrial Area, Phase III',
                'pickup_city' => 'New Delhi',
                'pickup_state' => 'Delhi',
                'pickup_pincode' => '110020',
                'pan' => 'AAACD1111A',
                'gstin' => '07AAACD1111A1Z1',
                'status' => SellerStatus::Approved,
                'step' => 5,
            ],
            2 => [
                'name' => 'Kareena Merchant',
                'email' => 'seller2@example.com',
                'store_name' => 'Bombay Threads & Trends',
                'store_slug' => 'bombay-threads',
                'business_type' => 'individual',
                'description' => 'Trendy fashion wear, premium cotton t-shirts, and casual clothing.',
                'support_phone' => '9822002200',
                'pickup_line1' => 'Unit 204, Lower Parel Mill Compound',
                'pickup_city' => 'Mumbai',
                'pickup_state' => 'Maharashtra',
                'pickup_pincode' => '400013',
                'pan' => 'BBBCD2222B',
                'gstin' => '27BBBCD2222B1Z2',
                'status' => SellerStatus::Approved,
                'step' => 5,
            ],
            3 => [
                'name' => 'Karthik Raman',
                'email' => 'seller3@example.com',
                'store_name' => 'Silicon Gadgets Hub',
                'store_slug' => 'silicon-gadgets',
                'business_type' => 'company',
                'description' => 'Flagship smartphones, tech accessories, and smart consumer devices.',
                'support_phone' => '9833003300',
                'pickup_line1' => '7th Cross, Koramangala Industrial Layout',
                'pickup_city' => 'Bengaluru',
                'pickup_state' => 'Karnataka',
                'pickup_pincode' => '560034',
                'pan' => 'CCCCD3333C',
                'gstin' => '29CCCCD3333C1Z3',
                'status' => SellerStatus::Approved,
                'step' => 5,
            ],
            4 => [
                'name' => 'Vikramaditya Rathore',
                'email' => 'seller4@example.com',
                'store_name' => 'Royal Rajputana Emporium',
                'store_slug' => 'royal-rajputana',
                'business_type' => 'partnership',
                'description' => 'Authentic action figures, handcrafted sets, and novelty games.',
                'support_phone' => '9844004400',
                'pickup_line1' => '88 MI Road, Near Raj Mandir',
                'pickup_city' => 'Jaipur',
                'pickup_state' => 'Rajasthan',
                'pickup_pincode' => '302001',
                'pan' => 'DDDDD4444D',
                'gstin' => '08DDDDD4444D1Z4',
                'status' => SellerStatus::Approved,
                'step' => 5,
            ],
            5 => [
                'name' => 'Debolina Banerjee',
                'email' => 'seller5@example.com',
                'store_name' => 'Howrah Little Stars',
                'store_slug' => 'howrah-little-stars',
                'business_type' => 'individual',
                'description' => 'Colorful kids wear, casual tops, and soft fabric apparel.',
                'support_phone' => '9855005500',
                'pickup_line1' => '12 Foreshore Road, Shibpur',
                'pickup_city' => 'Kolkata',
                'pickup_state' => 'West Bengal',
                'pickup_pincode' => '711102',
                'pan' => 'EEEED5555E',
                'gstin' => '19EEEED5555E1Z5',
                'status' => SellerStatus::Approved,
                'step' => 5,
            ],
            6 => [
                'name' => 'Suresh Reddy',
                'email' => 'seller6@example.com',
                'store_name' => 'Deccan Tech World',
                'store_slug' => 'deccan-tech',
                'business_type' => 'company',
                'description' => 'High-performance gadgets, smartphones, gaming accessories, and electronics.',
                'support_phone' => '9866006600',
                'pickup_line1' => 'Plot 45, HITEC City Phase 2',
                'pickup_city' => 'Hyderabad',
                'pickup_state' => 'Telangana',
                'pickup_pincode' => '500081',
                'pan' => 'FFFFD6666F',
                'gstin' => '36FFFFD6666F1Z6',
                'status' => SellerStatus::Approved,
                'step' => 5,
            ],
            7 => [
                'name' => 'Anand Sundaram',
                'email' => 'seller7@example.com',
                'store_name' => 'Chennai Horizon Retails',
                'store_slug' => 'chennai-horizon',
                'business_type' => 'company',
                'description' => 'Application under review for toys and collectibles distribution.',
                'support_phone' => '9877007700',
                'pickup_line1' => '22 Mount Road, Guindy',
                'pickup_city' => 'Chennai',
                'pickup_state' => 'Tamil Nadu',
                'pickup_pincode' => '600032',
                'pan' => 'GGGG7777G',
                'gstin' => '33GGGG7777G1Z7',
                'status' => SellerStatus::Pending,
                'step' => 4,
            ],
            8 => [
                'name' => 'Harpreet Singh Dhillon',
                'email' => 'seller8@example.com',
                'store_name' => 'Punjab Express Traders',
                'store_slug' => 'punjab-express',
                'business_type' => 'individual',
                'description' => 'Account suspended pending compliance review.',
                'support_phone' => '9888008800',
                'pickup_line1' => 'GT Road, Miller Ganj',
                'pickup_city' => 'Ludhiana',
                'pickup_state' => 'Punjab',
                'pickup_pincode' => '141003',
                'pan' => 'HHHH8888H',
                'gstin' => '03HHHH8888H1Z8',
                'status' => SellerStatus::Suspended,
                'step' => 5,
            ],
        ];

        $profiles = [];

        foreach ($sellersConfig as $idx => $cfg) {
            $user = User::firstOrCreate(
                ['email' => $cfg['email']],
                [
                    'name' => $cfg['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]
            );

            $profile = SellerProfile::firstOrCreate(
                ['store_slug' => $cfg['store_slug']],
                [
                    'user_id' => $user->id,
                    'store_name' => $cfg['store_name'],
                    'business_type' => $cfg['business_type'],
                    'description' => $cfg['description'],
                    'support_email' => $cfg['email'],
                    'support_phone' => $cfg['support_phone'],
                    'pickup_address_line1' => $cfg['pickup_line1'],
                    'pickup_city' => $cfg['pickup_city'],
                    'pickup_state' => $cfg['pickup_state'],
                    'pickup_postal_code' => $cfg['pickup_pincode'],
                    'pickup_country' => 'India',
                    'pan' => $cfg['pan'],
                    'gstin' => $cfg['gstin'],
                    'bank_account_holder' => $cfg['name'],
                    'bank_account_number' => '9100'.str_pad((string) $idx, 8, '0', STR_PAD_LEFT),
                    'ifsc' => 'HDFC000'.str_pad((string) $idx, 4, '0', STR_PAD_LEFT),
                    'status' => $cfg['status'],
                    'current_step' => $cfg['step'],
                    'submitted_at' => now()->subDays(65),
                    'approved_at' => $cfg['status'] === SellerStatus::Approved ? now()->subDays(62) : null,
                    'reviewed_at' => $cfg['status'] !== SellerStatus::Pending ? now()->subDays(62) : null,
                ]
            );

            $profiles[$idx] = $profile;
        }

        return $profiles;
    }

    /**
     * Seed 60 products across 5 verticals with 1-6 variants each and multi-seller competition.
     *
     * @param  array<int, SellerProfile>  $sellers
     * @return array<int, Product>
     */
    protected function seedProductsAndListings(array $sellers): array
    {
        $verticals = Vertical::all()->keyBy('slug');
        $brands = Brand::where('status', BrandStatus::Approved)->get()->keyBy('slug');

        $genericBrand = $brands['generic'] ?? Brand::firstOrCreate(['slug' => 'generic'], ['name' => 'Generic', 'status' => BrandStatus::Approved]);

        $approvedSellers = array_filter($sellers, fn (SellerProfile $s) => $s->status === SellerStatus::Approved);
        $suspendedSeller = $sellers[8];

        $catalogPlan = [
            // 1. Board Games (12 products)
            'board-game' => [
                'vertical' => $verticals['board-game'],
                'items' => [
                    ['title' => 'Chambers of Strategy Board Game', 'brand' => $brands['hasbro'] ?? $genericBrand, 'variants' => [['edition' => 'standard', 'mrp' => 129900, 'price' => 99900, 'stock' => 25], ['edition' => 'deluxe', 'mrp' => 199900, 'price' => 159900, 'stock' => 15]]],
                    ['title' => 'Mystery Mansion Detective Game', 'brand' => $brands['mattel'] ?? $genericBrand, 'variants' => [['edition' => 'standard', 'mrp' => 99900, 'price' => 79900, 'stock' => 30], ['edition' => 'collectors', 'mrp' => 249900, 'price' => 199900, 'stock' => 8]]],
                    ['title' => 'Galactic Conquest Space Game', 'brand' => $brands['hasbro'] ?? $genericBrand, 'variants' => [['edition' => 'standard', 'mrp' => 159900, 'price' => 129900, 'stock' => 20], ['edition' => 'deluxe', 'mrp' => 229900, 'price' => 189900, 'stock' => 12]]],
                    ['title' => 'Kingdoms & Castles Siege Game', 'brand' => $genericBrand, 'variants' => [['edition' => 'standard', 'mrp' => 89900, 'price' => 69900, 'stock' => 40]]],
                    ['title' => 'Ancient Treasures Gold Quest', 'brand' => $brands['mattel'] ?? $genericBrand, 'variants' => [['edition' => 'standard', 'mrp' => 119900, 'price' => 89900, 'stock' => 18], ['edition' => 'deluxe', 'mrp' => 179900, 'price' => 144900, 'stock' => 5]]],
                    ['title' => 'Speedway Turbo Race Board Game', 'brand' => $brands['hot-wheels'] ?? $genericBrand, 'variants' => [['edition' => 'standard', 'mrp' => 149900, 'price' => 119900, 'stock' => 22]]],
                    ['title' => 'Island Survival Expedition', 'brand' => $genericBrand, 'variants' => [['edition' => 'standard', 'mrp' => 109900, 'price' => 84900, 'stock' => 35], ['edition' => 'collectors', 'mrp' => 219900, 'price' => 179900, 'stock' => 4]]],
                    ['title' => 'Word Craft Vocabulary Challenge', 'brand' => $brands['hasbro'] ?? $genericBrand, 'variants' => [['edition' => 'standard', 'mrp' => 79900, 'price' => 59900, 'stock' => 50]]],
                    ['title' => 'Dragon Citadel Fantasy Board Game', 'brand' => $genericBrand, 'variants' => [['edition' => 'standard', 'mrp' => 169900, 'price' => 134900, 'stock' => 16], ['edition' => 'deluxe', 'mrp' => 259900, 'price' => 219900, 'stock' => 10]]],
                    ['title' => 'Junior Detective Clue Board Game', 'brand' => $brands['mattel'] ?? $genericBrand, 'variants' => [['edition' => 'standard', 'mrp' => 89900, 'price' => 69900, 'stock' => 28]]],
                    ['title' => 'World Empires Historical Strategy', 'brand' => $brands['hasbro'] ?? $genericBrand, 'variants' => [['edition' => 'standard', 'mrp' => 229900, 'price' => 189900, 'stock' => 14], ['edition' => 'collectors', 'mrp' => 349900, 'price' => 299900, 'stock' => 6]]],
                    ['title' => 'Traffic Rush City Grid Game', 'brand' => $genericBrand, 'variants' => [['edition' => 'standard', 'mrp' => 69900, 'price' => 49900, 'stock' => 45]]],
                ],
            ],

            // 2. Action Figures (12 products)
            'action-figure' => [
                'vertical' => $verticals['action-figure'],
                'items' => [
                    ['title' => 'Avenger Armored Battle Hero', 'brand' => $brands['hasbro'] ?? $genericBrand, 'variants' => [['character' => 'iron-man', 'mrp' => 149900, 'price' => 119900, 'stock' => 30], ['character' => 'spider-man', 'mrp' => 139900, 'price' => 109900, 'stock' => 25]]],
                    ['title' => 'Dark Knight Gotham Guardian', 'brand' => $brands['funko'] ?? $genericBrand, 'variants' => [['character' => 'batman', 'mrp' => 179900, 'price' => 149900, 'stock' => 20]]],
                    ['title' => 'Galactic Sith Overlord Figure', 'brand' => $brands['hasbro'] ?? $genericBrand, 'variants' => [['character' => 'darth-vader', 'mrp' => 199900, 'price' => 169900, 'stock' => 15]]],
                    ['title' => 'Web Slinger Action Model', 'brand' => $brands['hasbro'] ?? $genericBrand, 'variants' => [['character' => 'spider-man', 'mrp' => 119900, 'price' => 89900, 'stock' => 35]]],
                    ['title' => 'Titanium Armor Flight Warrior', 'brand' => $genericBrand, 'variants' => [['character' => 'iron-man', 'mrp' => 129900, 'price' => 99900, 'stock' => 18]]],
                    ['title' => 'Shadow Vigilante Cape Warrior', 'brand' => $brands['mattel'] ?? $genericBrand, 'variants' => [['character' => 'batman', 'mrp' => 159900, 'price' => 129900, 'stock' => 12]]],
                    ['title' => 'Imperial Commander Force Figure', 'brand' => $brands['funko'] ?? $genericBrand, 'variants' => [['character' => 'darth-vader', 'mrp' => 149900, 'price' => 119900, 'stock' => 24]]],
                    ['title' => 'Street Patrol Red Hero Figure', 'brand' => $genericBrand, 'variants' => [['character' => 'spider-man', 'mrp' => 99900, 'price' => 74900, 'stock' => 28]]],
                    ['title' => 'Cyber Tech Armored Buster', 'brand' => $brands['hasbro'] ?? $genericBrand, 'variants' => [['character' => 'iron-man', 'mrp' => 249900, 'price' => 199900, 'stock' => 10]]],
                    ['title' => 'Midnight Bat Cruiser Figure', 'brand' => $brands['hot-wheels'] ?? $genericBrand, 'variants' => [['character' => 'batman', 'mrp' => 169900, 'price' => 139900, 'stock' => 16]]],
                    ['title' => 'Crimson Sith Apprentice Figure', 'brand' => $genericBrand, 'variants' => [['character' => 'darth-vader', 'mrp' => 139900, 'price' => 109900, 'stock' => 22]]],
                    ['title' => 'Wall Crawler Stealth Edition', 'brand' => $brands['funko'] ?? $genericBrand, 'variants' => [['character' => 'spider-man', 'mrp' => 159900, 'price' => 129900, 'stock' => 14]]],
                ],
            ],

            // 3. Building Blocks (12 products)
            'building-blocks' => [
                'vertical' => $verticals['building-blocks'],
                'items' => [
                    ['title' => 'Metro Police & Fire Station Set', 'brand' => $brands['lego'] ?? $genericBrand, 'variants' => [['theme' => 'city', 'mrp' => 399900, 'price' => 329900, 'stock' => 20]]],
                    ['title' => 'Lunar Explorer Space Base Kit', 'brand' => $brands['lego'] ?? $genericBrand, 'variants' => [['theme' => 'space', 'mrp' => 499900, 'price' => 419900, 'stock' => 15]]],
                    ['title' => 'Medieval King Fortress Castle', 'brand' => $brands['lego'] ?? $genericBrand, 'variants' => [['theme' => 'castle', 'mrp' => 599900, 'price' => 499900, 'stock' => 12]]],
                    ['title' => 'Heroic City Defense Headquarters', 'brand' => $brands['lego'] ?? $genericBrand, 'variants' => [['theme' => 'superheroes', 'mrp' => 449900, 'price' => 379900, 'stock' => 18]]],
                    ['title' => 'Downtown Modern Highrise Tower', 'brand' => $genericBrand, 'variants' => [['theme' => 'city', 'mrp' => 249900, 'price' => 199900, 'stock' => 25]]],
                    ['title' => 'Interstellar Warp Cruiser Shuttle', 'brand' => $genericBrand, 'variants' => [['theme' => 'space', 'mrp' => 299900, 'price' => 239900, 'stock' => 22]]],
                    ['title' => 'Black Dragon Fortress Outpost', 'brand' => $genericBrand, 'variants' => [['theme' => 'castle', 'mrp' => 349900, 'price' => 289900, 'stock' => 16]]],
                    ['title' => 'Heroic Speedster Vehicle Garage', 'brand' => $brands['lego'] ?? $genericBrand, 'variants' => [['theme' => 'superheroes', 'mrp' => 199900, 'price' => 159900, 'stock' => 30]]],
                    ['title' => 'Grand Harbor Freight Ship & Port', 'brand' => $brands['lego'] ?? $genericBrand, 'variants' => [['theme' => 'city', 'mrp' => 479900, 'price' => 399900, 'stock' => 14]]],
                    ['title' => 'Deep Orbit Satellite Station', 'brand' => $brands['lego'] ?? $genericBrand, 'variants' => [['theme' => 'space', 'mrp' => 369900, 'price' => 299900, 'stock' => 18]]],
                    ['title' => 'Royal Guard Drawbridge Watchtower', 'brand' => $genericBrand, 'variants' => [['theme' => 'castle', 'mrp' => 219900, 'price' => 169900, 'stock' => 28]]],
                    ['title' => 'Vigilante Mech Armor Set', 'brand' => $genericBrand, 'variants' => [['theme' => 'superheroes', 'mrp' => 279900, 'price' => 219900, 'stock' => 20]]],
                ],
            ],

            // 4. T-Shirts (12 products)
            't-shirt' => [
                'vertical' => $verticals['t-shirt'],
                'items' => [
                    ['title' => 'Classic Cotton Crewneck Tee', 'brand' => $brands['nike'] ?? $genericBrand, 'variants' => [['size' => 'm', 'color' => 'black', 'mrp' => 129900, 'price' => 99900, 'stock' => 40], ['size' => 'l', 'color' => 'black', 'mrp' => 129900, 'price' => 99900, 'stock' => 35], ['size' => 'm', 'color' => 'white', 'mrp' => 129900, 'price' => 99900, 'stock' => 30]]],
                    ['title' => 'Dry-Fit Athletic Training Tee', 'brand' => $brands['puma'] ?? $genericBrand, 'variants' => [['size' => 'm', 'color' => 'navy-blue', 'mrp' => 149900, 'price' => 119900, 'stock' => 25], ['size' => 'l', 'color' => 'navy-blue', 'mrp' => 149900, 'price' => 119900, 'stock' => 20]]],
                    ['title' => 'Graphic Streetwear Heavy Tee', 'brand' => $genericBrand, 'variants' => [['size' => 'l', 'color' => 'black', 'mrp' => 99900, 'price' => 69900, 'stock' => 50], ['size' => 'xl', 'color' => 'black', 'mrp' => 99900, 'price' => 69900, 'stock' => 45]]],
                    ['title' => 'Solid Minimalist Casual Tee', 'brand' => $genericBrand, 'variants' => [['size' => 'm', 'color' => 'red', 'mrp' => 79900, 'price' => 54900, 'stock' => 35], ['size' => 'l', 'color' => 'red', 'mrp' => 79900, 'price' => 54900, 'stock' => 30]]],
                    ['title' => 'Sport Performance Breathe Top', 'brand' => $brands['nike'] ?? $genericBrand, 'variants' => [['size' => 'm', 'color' => 'white', 'mrp' => 179900, 'price' => 139900, 'stock' => 20], ['size' => 'l', 'color' => 'white', 'mrp' => 179900, 'price' => 139900, 'stock' => 15]]],
                    ['title' => 'Retro Vintage Wash Cotton Tee', 'brand' => $genericBrand, 'variants' => [['size' => 's', 'color' => 'navy-blue', 'mrp' => 89900, 'price' => 64900, 'stock' => 30], ['size' => 'm', 'color' => 'navy-blue', 'mrp' => 89900, 'price' => 64900, 'stock' => 25]]],
                    ['title' => 'Urban Velocity Active T-Shirt', 'brand' => $brands['puma'] ?? $genericBrand, 'variants' => [['size' => 'l', 'color' => 'black', 'mrp' => 159900, 'price' => 124900, 'stock' => 22]]],
                    ['title' => 'Pure Organic Cotton Daily Tee', 'brand' => $genericBrand, 'variants' => [['size' => 'm', 'color' => 'white', 'mrp' => 89900, 'price' => 59900, 'stock' => 40], ['size' => 'l', 'color' => 'white', 'mrp' => 89900, 'price' => 59900, 'stock' => 35]]],
                    ['title' => 'Stamina Gym Flex Athletic Tee', 'brand' => $brands['nike'] ?? $genericBrand, 'variants' => [['size' => 'm', 'color' => 'red', 'mrp' => 169900, 'price' => 129900, 'stock' => 18]]],
                    ['title' => 'Oversized Boxy Fit Street Top', 'brand' => $genericBrand, 'variants' => [['size' => 'l', 'color' => 'black', 'mrp' => 119900, 'price' => 79900, 'stock' => 28], ['size' => 'xl', 'color' => 'black', 'mrp' => 119900, 'price' => 79900, 'stock' => 20]]],
                    ['title' => 'Puma Heritage Sport Crew Tee', 'brand' => $brands['puma'] ?? $genericBrand, 'variants' => [['size' => 'm', 'color' => 'navy-blue', 'mrp' => 139900, 'price' => 109900, 'stock' => 26]]],
                    ['title' => 'Summer Breeze Lightweight Tee', 'brand' => $genericBrand, 'variants' => [['size' => 's', 'color' => 'white', 'mrp' => 69900, 'price' => 49900, 'stock' => 45], ['size' => 'm', 'color' => 'white', 'mrp' => 69900, 'price' => 49900, 'stock' => 40]]],
                ],
            ],

            // 5. Smartphones (12 products)
            'smartphone' => [
                'vertical' => $verticals['smartphone'],
                'items' => [
                    ['title' => 'Galaxy Nova Pro 5G Phone', 'brand' => $brands['samsung'] ?? $genericBrand, 'variants' => [['ram' => '8gb', 'storage' => '128gb', 'color' => 'midnight-black', 'mrp' => 3499900, 'price' => 2999900, 'stock' => 20], ['ram' => '8gb', 'storage' => '256gb', 'color' => 'ocean-blue', 'mrp' => 3899900, 'price' => 3399900, 'stock' => 15]]],
                    ['title' => 'iDevice Pro Titanium Smartphone', 'brand' => $brands['apple'] ?? $genericBrand, 'variants' => [['ram' => '8gb', 'storage' => '256gb', 'color' => 'starlight', 'mrp' => 8999900, 'price' => 8499900, 'stock' => 10], ['ram' => '8gb', 'storage' => '512gb', 'color' => 'midnight-black', 'mrp' => 10499900, 'price' => 9999900, 'stock' => 8]]],
                    ['title' => 'Galaxy Prime Budget 5G Mobile', 'brand' => $brands['samsung'] ?? $genericBrand, 'variants' => [['ram' => '6gb', 'storage' => '128gb', 'color' => 'midnight-black', 'mrp' => 1999900, 'price' => 1649900, 'stock' => 30]]],
                    ['title' => 'Apex Speedster Performance Phone', 'brand' => $genericBrand, 'variants' => [['ram' => '12gb', 'storage' => '256gb', 'color' => 'ocean-blue', 'mrp' => 2999900, 'price' => 2499900, 'stock' => 25]]],
                    ['title' => 'iDevice Compact Standard Edition', 'brand' => $brands['apple'] ?? $genericBrand, 'variants' => [['ram' => '6gb', 'storage' => '128gb', 'color' => 'starlight', 'mrp' => 6499900, 'price' => 5999900, 'stock' => 12]]],
                    ['title' => 'Galaxy Ultra Vision Flagship Phone', 'brand' => $brands['samsung'] ?? $genericBrand, 'variants' => [['ram' => '12gb', 'storage' => '256gb', 'color' => 'midnight-black', 'mrp' => 7499900, 'price' => 6999900, 'stock' => 14]]],
                    ['title' => 'Zenith Power Long Battery Smartphone', 'brand' => $genericBrand, 'variants' => [['ram' => '6gb', 'storage' => '128gb', 'color' => 'ocean-blue', 'mrp' => 1699900, 'price' => 1399900, 'stock' => 35]]],
                    ['title' => 'Galaxy Slim Neo 5G Mobile', 'brand' => $brands['samsung'] ?? $genericBrand, 'variants' => [['ram' => '8gb', 'storage' => '128gb', 'color' => 'starlight', 'mrp' => 2599900, 'price' => 2199900, 'stock' => 22]]],
                    ['title' => 'iDevice Max Extreme Edition', 'brand' => $brands['apple'] ?? $genericBrand, 'variants' => [['ram' => '8gb', 'storage' => '512gb', 'color' => 'midnight-black', 'mrp' => 11999900, 'price' => 11499900, 'stock' => 6]]],
                    ['title' => 'Verve Urban Smart Handset', 'brand' => $genericBrand, 'variants' => [['ram' => '4gb', 'storage' => '64gb', 'color' => 'midnight-black', 'mrp' => 1199900, 'price' => 899900, 'stock' => 40]]],
                    ['title' => 'Galaxy Fold Duo Dual Screen Phone', 'brand' => $brands['samsung'] ?? $genericBrand, 'variants' => [['ram' => '12gb', 'storage' => '512gb', 'color' => 'ocean-blue', 'mrp' => 12999900, 'price' => 12199900, 'stock' => 8]]],
                    ['title' => 'Hyperion Gamer Pro Smartphone', 'brand' => $genericBrand, 'variants' => [['ram' => '12gb', 'storage' => '256gb', 'color' => 'midnight-black', 'mrp' => 3999900, 'price' => 3499900, 'stock' => 16]]],
                ],
            ],
        ];

        $createdProducts = [];
        $variantsForCompetition = [];
        $sellerKeys = array_keys($approvedSellers);

        $productCounter = 0;
        foreach ($catalogPlan as $vertKey => $vGroup) {
            $vert = $vGroup['vertical'];

            foreach ($vGroup['items'] as $itemIdx => $itemData) {
                $productCounter++;
                $creatorSeller = $approvedSellers[$sellerKeys[$productCounter % count($sellerKeys)]];
                $productSlug = 'demo-prod-'.Str::slug($vertKey)."-{$productCounter}";

                $product = Product::firstOrCreate(
                    ['slug' => $productSlug],
                    [
                        'vertical_id' => $vert->id,
                        'brand_id' => $itemData['brand']->id,
                        'title' => $itemData['title'],
                        'description' => "High quality {$itemData['title']} supplied by {$creatorSeller->store_name}.",
                        'qc_status' => ProductQcStatus::Approved,
                        'created_by' => $creatorSeller->user_id,
                    ]
                );

                // Add placeholder image
                ProductImage::firstOrCreate(
                    [
                        'product_id' => $product->id,
                        'path' => "products/{$vertKey}.svg",
                    ],
                    [
                        'is_primary' => true,
                        'sort_order' => 1,
                        'status' => ProductImageStatus::Live,
                    ]
                );

                // Create Variants
                foreach ($itemData['variants'] as $vIdx => $vData) {
                    $attrs = [];
                    foreach ($vData as $k => $v) {
                        if (! in_array($k, ['mrp', 'price', 'stock'], true)) {
                            $attrs[$k] = $v;
                        }
                    }

                    $variantKey = VariantKeyNormalizer::normalize($attrs);

                    $variant = ProductVariant::firstOrCreate(
                        [
                            'product_id' => $product->id,
                            'variant_key' => $variantKey,
                        ],
                        [
                            'upid' => UpidGenerator::generate(),
                            'variant_attributes' => $attrs,
                            'barcode' => '890'.str_pad((string) ($product->id * 100 + $vIdx), 10, '0', STR_PAD_LEFT),
                            'weight' => 0.5,
                            'dimensions' => ['length' => 20, 'width' => 15, 'height' => 5],
                            'qc_status' => VariantQcStatus::Approved,
                        ]
                    );

                    // Primary Listing
                    Listing::firstOrCreate(
                        [
                            'seller_profile_id' => $creatorSeller->id,
                            'product_variant_id' => $variant->id,
                        ],
                        [
                            'sku' => strtoupper(substr($creatorSeller->store_slug, 0, 4))."-{$product->id}-{$vIdx}",
                            'mrp' => $vData['mrp'],
                            'selling_price' => $vData['price'],
                            'stock' => $vData['stock'],
                            'status' => ListingStatus::Active,
                        ]
                    );

                    // Collect candidates for multi-seller competition
                    if (count($variantsForCompetition) < 15 && $vIdx === 0 && ($itemIdx % 2 === 0)) {
                        $variantsForCompetition[] = [
                            'variant' => $variant,
                            'product' => $product,
                            'creatorSeller' => $creatorSeller,
                            'baseMrp' => $vData['mrp'],
                            'basePrice' => $vData['price'],
                        ];
                    }
                }

                $createdProducts[] = $product;
            }
        }

        // Add Multi-Seller Competition on the 15 selected variants
        $competingSellerPool = array_values($approvedSellers);
        foreach ($variantsForCompetition as $compIdx => $comp) {
            $variant = $comp['variant'];
            $creator = $comp['creatorSeller'];
            $baseMrp = $comp['baseMrp'];
            $basePrice = $comp['basePrice'];

            // Filter sellers other than creator
            $otherSellers = array_values(array_filter($competingSellerPool, fn ($s) => $s->id !== $creator->id));
            shuffle($otherSellers);

            // In half of the competitive variants, the lowest price seller is OUT OF STOCK (stock = 0),
            // so the next lowest priced seller wins the buy box!
            $outOfStockCheap = ($compIdx % 2 === 0);

            // Seller 1: cheaper price (out of stock if $outOfStockCheap, otherwise low stock)
            if (isset($otherSellers[0])) {
                $sellerA = $otherSellers[0];
                $priceA = (int) max(1000, round($basePrice * 0.92));
                Listing::firstOrCreate(
                    [
                        'seller_profile_id' => $sellerA->id,
                        'product_variant_id' => $variant->id,
                    ],
                    [
                        'sku' => strtoupper(substr($sellerA->store_slug, 0, 4))."-COMP-{$variant->id}-A",
                        'mrp' => $baseMrp,
                        'selling_price' => $priceA,
                        'stock' => $outOfStockCheap ? 0 : 5,
                        'status' => ListingStatus::Active,
                    ]
                );
            }

            // Seller 2: healthy stock at moderate price
            if (isset($otherSellers[1])) {
                $sellerB = $otherSellers[1];
                $priceB = (int) max(1000, round($basePrice * 0.97));
                Listing::firstOrCreate(
                    [
                        'seller_profile_id' => $sellerB->id,
                        'product_variant_id' => $variant->id,
                    ],
                    [
                        'sku' => strtoupper(substr($sellerB->store_slug, 0, 4))."-COMP-{$variant->id}-B",
                        'mrp' => $baseMrp,
                        'selling_price' => $priceB,
                        'stock' => 30, // healthy stock wins buy box when A is out of stock
                        'status' => ListingStatus::Active,
                    ]
                );
            }

            // Seller 3: higher price
            if (isset($otherSellers[2]) && $compIdx % 3 === 0) {
                $sellerC = $otherSellers[2];
                $priceC = (int) min($baseMrp, round($basePrice * 1.05));
                Listing::firstOrCreate(
                    [
                        'seller_profile_id' => $sellerC->id,
                        'product_variant_id' => $variant->id,
                    ],
                    [
                        'sku' => strtoupper(substr($sellerC->store_slug, 0, 4))."-COMP-{$variant->id}-C",
                        'mrp' => $baseMrp,
                        'selling_price' => $priceC,
                        'stock' => 12,
                        'status' => ListingStatus::Active,
                    ]
                );
            }
        }

        // Add inactive listings for Suspended Seller 8 (ensuring catalog audit passes and they don't appear in storefront)
        $sampleVariants = ProductVariant::take(4)->get();
        foreach ($sampleVariants as $sIdx => $sVar) {
            Listing::firstOrCreate(
                [
                    'seller_profile_id' => $suspendedSeller->id,
                    'product_variant_id' => $sVar->id,
                ],
                [
                    'sku' => "PUNJAB-SUSPENDED-{$sVar->id}-{$sIdx}",
                    'mrp' => 200000,
                    'selling_price' => 150000,
                    'stock' => 10,
                    'status' => ListingStatus::Inactive, // Inactive so non-approved seller check passes
                ]
            );
        }

        // Refresh all buy box calculations
        app(ProductBuyBoxService::class)->refreshAll();

        return $createdProducts;
    }

    /**
     * Seed 20 customers with addresses across different Indian states.
     *
     * @return array<int, User>
     */
    protected function seedCustomers(): array
    {
        $customersData = [
            1 => ['name' => 'Aarav Sharma', 'phone' => '9811122233', 'state' => 'Delhi', 'city' => 'New Delhi', 'pincode' => '110001', 'address' => '42 Connaught Circus'],
            2 => ['name' => 'Priya Patel', 'phone' => '9822233344', 'state' => 'Gujarat', 'city' => 'Ahmedabad', 'pincode' => '380015', 'address' => '12 SG Highway, Bodakdev'],
            3 => ['name' => 'Rohan Iyer', 'phone' => '9833344455', 'state' => 'Karnataka', 'city' => 'Bengaluru', 'pincode' => '560038', 'address' => '104 100ft Road, Indiranagar'],
            4 => ['name' => 'Ananya Gupta', 'phone' => '9844455566', 'state' => 'Uttar Pradesh', 'city' => 'Noida', 'pincode' => '201301', 'address' => 'Tower 4, Sector 62'],
            5 => ['name' => 'Vikram Singh', 'phone' => '9855566677', 'state' => 'Rajasthan', 'city' => 'Jaipur', 'pincode' => '302004', 'address' => '18 Tilak Nagar'],
            6 => ['name' => 'Neha Verma', 'phone' => '9866677788', 'state' => 'Maharashtra', 'city' => 'Pune', 'pincode' => '411004', 'address' => '55 FC Road, Shivaji Nagar'],
            7 => ['name' => 'Aditya Nair', 'phone' => '9877788899', 'state' => 'Kerala', 'city' => 'Kochi', 'pincode' => '682016', 'address' => '7 Marine Drive'],
            8 => ['name' => 'Kavya Sen', 'phone' => '9888899900', 'state' => 'West Bengal', 'city' => 'Kolkata', 'pincode' => '700019', 'address' => '33 Ballygunge Circular Rd'],
            9 => ['name' => 'Siddharth Reddy', 'phone' => '9899900011', 'state' => 'Telangana', 'city' => 'Hyderabad', 'pincode' => '500034', 'address' => 'Road No 12, Banjara Hills'],
            10 => ['name' => 'Pooja Joshi', 'phone' => '9810011223', 'state' => 'Maharashtra', 'city' => 'Mumbai', 'pincode' => '400050', 'address' => '24 Hill Road, Bandra West'],
            11 => ['name' => 'Rahul Mehta', 'phone' => '9820022334', 'state' => 'Gujarat', 'city' => 'Surat', 'pincode' => '395007', 'address' => '8 Ring Road, Vesu'],
            12 => ['name' => 'Riya Mukherjee', 'phone' => '9830033445', 'state' => 'West Bengal', 'city' => 'Kolkata', 'pincode' => '700064', 'address' => 'Salt Lake Sector 1'],
            13 => ['name' => 'Arjun Deshmukh', 'phone' => '9840044556', 'state' => 'Maharashtra', 'city' => 'Nagpur', 'pincode' => '440010', 'address' => '15 Dharampeth'],
            14 => ['name' => 'Shreya Bhat', 'phone' => '9850055667', 'state' => 'Karnataka', 'city' => 'Mangaluru', 'pincode' => '575003', 'address' => 'Kadri Temple Road'],
            15 => ['name' => 'Manish Agarwal', 'phone' => '9860066778', 'state' => 'Rajasthan', 'city' => 'Udaipur', 'pincode' => '313001', 'address' => 'Lake Palace Road'],
            16 => ['name' => 'Deepika Choudhury', 'phone' => '9870077889', 'state' => 'Delhi', 'city' => 'New Delhi', 'pincode' => '110017', 'address' => 'Malviya Nagar Block B'],
            17 => ['name' => 'Karan Malhotra', 'phone' => '9880088990', 'state' => 'Punjab', 'city' => 'Amritsar', 'pincode' => '143001', 'address' => 'Mall Road, Civil Lines'],
            18 => ['name' => 'Sneha Rao', 'phone' => '9890099001', 'state' => 'Karnataka', 'city' => 'Mysuru', 'pincode' => '570001', 'address' => 'Sayyaji Rao Road'],
            19 => ['name' => 'Varun Pillai', 'phone' => '9811223344', 'state' => 'Tamil Nadu', 'city' => 'Coimbatore', 'pincode' => '641001', 'address' => 'Avinashi Road'],
            20 => ['name' => 'Tanvi Kulkarni', 'phone' => '9822334455', 'state' => 'Maharashtra', 'city' => 'Nashik', 'pincode' => '422005', 'address' => 'College Road'],
        ];

        $users = [];
        foreach ($customersData as $i => $data) {
            $email = "customer{$i}@example.com";
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]
            );

            // Add primary default address
            CustomerAddress::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'name' => $data['name'],
                    'address_line' => $data['address'],
                ],
                [
                    'phone' => $data['phone'],
                    'locality' => 'Main Town',
                    'city' => $data['city'],
                    'state' => $data['state'],
                    'pincode' => $data['pincode'],
                    'type' => 'home',
                    'is_default' => true,
                ]
            );

            // Add secondary office address for some customers
            if ($i % 2 === 0) {
                CustomerAddress::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'name' => "{$data['name']} (Work)",
                        'address_line' => "Tech Park, Building {$i}, Phase 1",
                    ],
                    [
                        'phone' => $data['phone'],
                        'locality' => 'IT Corridor',
                        'city' => $data['city'],
                        'state' => $data['state'],
                        'pincode' => $data['pincode'],
                        'type' => 'work',
                        'is_default' => false,
                    ]
                );
            }

            $users[$i] = $user;
        }

        return $users;
    }

    /**
     * Seed 80 simulated orders across the last 60 days using real PlaceOrderData and OrderService::place().
     *
     * @param  array<int, User>  $customers
     * @param  array<int, SellerProfile>  $sellers
     * @return array<string, mixed>
     */
    protected function seedOrders(array $customers, array $sellers): array
    {
        $demoEmails = collect(range(1, 20))->map(fn ($i) => "customer{$i}@example.com")->all();
        $demoCustomerIds = User::whereIn('email', $demoEmails)->pluck('id');

        $existingOrderCount = Order::whereIn('user_id', $demoCustomerIds)->count();
        if ($existingOrderCount > 0) {
            return [
                'skipped' => true,
                'existing_count' => $existingOrderCount,
                'orders_created' => 0,
                'lines_skipped' => 0,
                'orders_skipped' => 0,
            ];
        }

        $orderService = app(OrderService::class);

        // Fetch active listings from approved sellers
        $approvedSellerIds = collect($sellers)->where('status', SellerStatus::Approved)->pluck('id')->all();
        $activeListings = Listing::where('status', ListingStatus::Active)
            ->whereIn('seller_profile_id', $approvedSellerIds)
            ->with(['variant.product', 'sellerProfile'])
            ->get();

        if ($activeListings->isEmpty()) {
            return ['skipped' => true, 'existing_count' => 0, 'orders_created' => 0, 'lines_skipped' => 0, 'orders_skipped' => 0];
        }

        // Group listings by seller
        $listingsBySeller = $activeListings->groupBy('seller_profile_id');
        $availableSellerIds = $listingsBySeller->keys()->all();

        // Target: 80 orders
        $totalOrdersTarget = 80;
        $ordersCreated = 0;
        $linesSkipped = 0;
        $ordersSkipped = 0;

        // Customer order distribution:
        // Heavy: customer1 (10), customer2 (8), customer3 (8) = 26
        // Moderate: customer4..12 (5 each) = 45
        // Light: customer13..17 (1-2 each) = 9
        // Inactive: customer18..20 (0)
        $customerAssignment = [];
        for ($k = 0; $k < 10; $k++) {
            $customerAssignment[] = 1;
        }
        for ($k = 0; $k < 8; $k++) {
            $customerAssignment[] = 2;
        }
        for ($k = 0; $k < 8; $k++) {
            $customerAssignment[] = 3;
        }
        for ($cId = 4; $cId <= 12; $cId++) {
            for ($k = 0; $k < 5; $k++) {
                $customerAssignment[] = $cId;
            }
        }
        for ($cId = 13; $cId <= 17; $cId++) {
            $customerAssignment[] = $cId;
            if ($cId <= 16) {
                $customerAssignment[] = $cId;
            }
        }
        // Trim or pad to exactly 80
        $customerAssignment = array_slice($customerAssignment, 0, $totalOrdersTarget);

        // Target status progressions for 80 orders:
        // ~30% Placed (24)
        // ~25% Confirmed (20)
        // ~20% Packed (16)
        // ~10% Delivered (8)
        // ~15% Cancelled (12: 6 fully cancelled, 6 partially cancelled)
        $targetStatuses = array_merge(
            array_fill(0, 24, 'placed'),
            array_fill(0, 20, 'confirmed'),
            array_fill(0, 16, 'packed'),
            array_fill(0, 8, 'delivered'),
            array_fill(0, 6, 'cancelled_full'),
            array_fill(0, 6, 'cancelled_partial')
        );
        shuffle($targetStatuses);

        for ($i = 0; $i < count($customerAssignment); $i++) {
            $customerIdx = $customerAssignment[$i];
            $customer = $customers[$customerIdx];
            $address = $customer->addresses()->where('is_default', true)->first() ?: $customer->addresses()->first();

            if (! $address) {
                continue;
            }

            // Distribute order dates chronologically over the last 58 days
            $minutesOffset = (int) ($i * (58 * 24 * 60 / $totalOrdersTarget));
            $orderDate = Carbon::now()->subDays(58)->addMinutes($minutesOffset);

            // Determine if multi-seller (~45% multi-seller, ~55% single-seller)
            $isMultiSeller = ($i % 2 === 0);
            $selectedListingIds = [];
            $lines = [];

            if ($isMultiSeller && count($availableSellerIds) >= 2) {
                // Select 2 to 3 distinct sellers
                $numSellers = ($i % 5 === 0 && count($availableSellerIds) >= 3) ? 3 : 2;
                $pickedSellerIds = (array) array_rand(array_flip($availableSellerIds), $numSellers);

                foreach ($pickedSellerIds as $sId) {
                    $sellerListings = $listingsBySeller[$sId]->shuffle();
                    $lineAdded = false;

                    foreach ($sellerListings as $candListing) {
                        $candListing = $candListing->fresh();
                        $qty = rand(1, 2);

                        if ($candListing && $candListing->stock >= $qty) {
                            $lines[] = [
                                'listing_id' => $candListing->id,
                                'qty' => $qty,
                            ];
                            $selectedListingIds[] = $candListing->id;
                            $lineAdded = true;
                            break;
                        } else {
                            $linesSkipped++;
                        }
                    }
                }
            } else {
                // Single-seller order
                $randomSellerId = $availableSellerIds[array_rand($availableSellerIds)];
                $sellerListings = $listingsBySeller[$randomSellerId]->shuffle();
                $numLines = ($i % 3 === 0) ? 2 : 1;

                foreach ($sellerListings as $candListing) {
                    if (count($lines) >= $numLines) {
                        break;
                    }
                    $candListing = $candListing->fresh();
                    $qty = rand(1, 2);

                    if ($candListing && $candListing->stock >= $qty && ! in_array($candListing->id, $selectedListingIds, true)) {
                        $lines[] = [
                            'listing_id' => $candListing->id,
                            'qty' => $qty,
                        ];
                        $selectedListingIds[] = $candListing->id;
                    } else {
                        $linesSkipped++;
                    }
                }
            }

            // If no lines could be formed due to out-of-stock, skip order
            if (empty($lines)) {
                $ordersSkipped++;

                continue;
            }

            // Calculate expected total accurately
            $expectedTotal = 0;
            $checkoutLines = [];
            foreach ($lines as $line) {
                $lModel = Listing::find($line['listing_id']);
                $expectedTotal += ((int) $lModel->selling_price) * $line['qty'];
                $checkoutLines[] = [
                    'listing_id' => $line['listing_id'],
                    'qty' => $line['qty'],
                    'unit_price' => (int) $lModel->selling_price,
                ];
            }

            $source = ($i % 4 === 0) ? 'buy_now' : 'cart';
            $targetOutcome = $targetStatuses[$i] ?? 'placed';

            try {
                // Wrap in Carbon::setTestNow and ensure finally resets it
                Carbon::setTestNow($orderDate);

                // Create CheckoutIntent with real ULID
                $intentId = (string) Str::ulid();
                $checkoutIntent = CheckoutIntent::create([
                    'id' => $intentId,
                    'user_id' => $customer->id,
                    'source' => $source,
                    'lines' => $checkoutLines,
                    'status' => 'completed',
                    'expires_at' => $orderDate->copy()->addHours(2),
                ]);

                // Place Order through real OrderService
                $placeData = new PlaceOrderData(
                    userId: $customer->id,
                    addressId: $address->id,
                    paymentMethod: 'cash_on_delivery',
                    lines: $lines,
                    expectedTotal: $expectedTotal,
                    idempotencyKey: $checkoutIntent->id,
                    source: $source
                );

                $order = $orderService->place($placeData);
                $ordersCreated++;

                // Progress Seller Orders according to target outcome
                $sellerOrders = $order->sellerOrders;

                if ($targetOutcome === 'confirmed') {
                    foreach ($sellerOrders as $so) {
                        Carbon::setTestNow($orderDate->copy()->addHours(3));
                        $orderService->transitionSellerOrderStatus($so, SellerOrderStatus::Confirmed, 'seller', $so->sellerProfile->user_id, 'Order confirmed by seller');
                    }
                } elseif ($targetOutcome === 'packed') {
                    foreach ($sellerOrders as $so) {
                        Carbon::setTestNow($orderDate->copy()->addHours(3));
                        $orderService->transitionSellerOrderStatus($so, SellerOrderStatus::Confirmed, 'seller', $so->sellerProfile->user_id);
                        Carbon::setTestNow($orderDate->copy()->addHours(12));
                        $orderService->transitionSellerOrderStatus($so, SellerOrderStatus::Packed, 'seller', $so->sellerProfile->user_id, 'Package packed and ready for dispatch');
                    }
                } elseif ($targetOutcome === 'delivered') {
                    foreach ($sellerOrders as $so) {
                        Carbon::setTestNow($orderDate->copy()->addHours(3));
                        $orderService->transitionSellerOrderStatus($so, SellerOrderStatus::Confirmed, 'seller', $so->sellerProfile->user_id);
                        Carbon::setTestNow($orderDate->copy()->addHours(12));
                        $orderService->transitionSellerOrderStatus($so, SellerOrderStatus::Packed, 'seller', $so->sellerProfile->user_id);
                        Carbon::setTestNow($orderDate->copy()->addHours(24));
                        $orderService->transitionSellerOrderStatus($so, SellerOrderStatus::Shipped, 'seller', $so->sellerProfile->user_id, 'Handed over to courier');
                        Carbon::setTestNow($orderDate->copy()->addDays(3));
                        $orderService->transitionSellerOrderStatus($so, SellerOrderStatus::Delivered, 'seller', $so->sellerProfile->user_id, 'Delivered to customer');
                    }
                } elseif ($targetOutcome === 'cancelled_full') {
                    // Fully cancelled by customer or seller
                    $isCustomerCancel = ($ordersCreated % 2 === 0);
                    foreach ($sellerOrders as $so) {
                        Carbon::setTestNow($orderDate->copy()->addHours(2));
                        if ($isCustomerCancel) {
                            $orderService->cancelSellerOrder($so, 'customer', $customer->id, 'Customer changed delivery preference');
                        } else {
                            $orderService->cancelSellerOrder($so, 'seller', $so->sellerProfile->user_id, 'Item damaged during warehouse check');
                        }
                    }
                } elseif ($targetOutcome === 'cancelled_partial') {
                    // In a multi-seller order, cancel 1 seller order while leaving others confirmed/delivered
                    if ($sellerOrders->count() > 1) {
                        $soToCancel = $sellerOrders->first();
                        Carbon::setTestNow($orderDate->copy()->addHours(2));
                        $orderService->cancelSellerOrder($soToCancel, 'customer', $customer->id, 'Customer requested package cancellation');

                        // Progress the remaining packages
                        foreach ($sellerOrders->slice(1) as $soRest) {
                            Carbon::setTestNow($orderDate->copy()->addHours(4));
                            $orderService->transitionSellerOrderStatus($soRest, SellerOrderStatus::Confirmed, 'seller', $soRest->sellerProfile->user_id);
                            Carbon::setTestNow($orderDate->copy()->addHours(16));
                            $orderService->transitionSellerOrderStatus($soRest, SellerOrderStatus::Packed, 'seller', $soRest->sellerProfile->user_id);
                        }
                    } else {
                        // If only 1 package, progress to confirmed
                        foreach ($sellerOrders as $so) {
                            Carbon::setTestNow($orderDate->copy()->addHours(3));
                            $orderService->transitionSellerOrderStatus($so, SellerOrderStatus::Confirmed, 'seller', $so->sellerProfile->user_id);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // If domain validation or concurrent issue occurs, safely record and proceed
                $ordersSkipped++;
            } finally {
                // Always reset Carbon time travel!
                Carbon::setTestNow();
            }
        }

        return [
            'skipped' => false,
            'existing_count' => 0,
            'orders_created' => $ordersCreated,
            'lines_skipped' => $linesSkipped,
            'orders_skipped' => $ordersSkipped,
        ];
    }

    /**
     * Compile statistical summary of seeded data.
     *
     * @param  array<int, SellerProfile>  $sellers
     * @param  array<int, Product>  $products
     * @param  array<int, User>  $customers
     * @param  array<string, mixed>  $orderStats
     */
    protected function compileStats(array $sellers, array $products, array $customers, array $orderStats): void
    {
        $approvedSellersCount = SellerProfile::where('status', SellerStatus::Approved)->count();
        $pendingSellersCount = SellerProfile::where('status', SellerStatus::Pending)->count();
        $suspendedSellersCount = SellerProfile::where('status', SellerStatus::Suspended)->count();

        $totalListings = Listing::count();

        // Competing variants: variants with listings from >1 seller
        $multiSellerVariantsCount = ProductVariant::has('listings', '>', 1)->count();

        $totalOrders = Order::count();
        $multiSellerOrdersCount = Order::has('sellerOrders', '>', 1)->count();
        $singleSellerOrdersCount = Order::has('sellerOrders', '=', 1)->count();

        $statusCounts = SellerOrder::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $grossValue = (int) SellerOrder::sum('grand_total');
        $cancelledValue = (int) SellerOrder::where('status', SellerOrderStatus::Cancelled)->sum('grand_total');
        $netValue = max(0, $grossValue - $cancelledValue);

        $this->stats = [
            'sellers' => [
                'total' => count($sellers),
                'approved' => $approvedSellersCount,
                'pending' => $pendingSellersCount,
                'suspended' => $suspendedSellersCount,
            ],
            'catalog' => [
                'products' => count($products),
                'total_products_in_db' => Product::count(),
                'listings' => $totalListings,
                'multi_seller_variants' => $multiSellerVariantsCount,
            ],
            'customers' => [
                'total' => count($customers),
                'total_in_db' => User::count(),
            ],
            'orders' => [
                'simulation_skipped' => $orderStats['skipped'] ?? false,
                'existing_orders' => $orderStats['existing_count'] ?? 0,
                'orders_created' => $orderStats['orders_created'] ?? 0,
                'lines_skipped_no_stock' => $orderStats['lines_skipped'] ?? 0,
                'orders_skipped_no_stock' => $orderStats['orders_skipped'] ?? 0,
                'total_orders' => $totalOrders,
                'single_seller_orders' => $singleSellerOrdersCount,
                'multi_seller_orders' => $multiSellerOrdersCount,
                'status_breakdown' => $statusCounts,
                'gross_value' => $grossValue,
                'cancelled_value' => $cancelledValue,
                'net_value' => $netValue,
            ],
        ];
    }
}
