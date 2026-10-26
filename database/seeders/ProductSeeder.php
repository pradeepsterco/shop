<?php

namespace Database\Seeders;

use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Brand;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Vertical;
use App\Services\ProductBuyBoxService;
use App\Services\UpidGenerator;
use App\Services\VariantKeyNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->isLocal() && ! app()->environment('testing')) {
            return;
        }

        $imagePaths = $this->ensurePlaceholderImages();

        // Fetch Approved Sellers
        $sellers = SellerProfile::where('status', SellerStatus::Approved)->get();
        if ($sellers->isEmpty()) {
            return;
        }
        $primarySeller = $sellers->first();
        $secondarySeller = $sellers->count() > 1 ? $sellers->skip(1)->first() : $primarySeller;

        // Fetch Verticals
        $vBoardGame = Vertical::where('slug', 'board-game')->first();
        $vActionFigure = Vertical::where('slug', 'action-figure')->first();
        $vBuildingBlocks = Vertical::where('slug', 'building-blocks')->first();
        $vTshirt = Vertical::where('slug', 't-shirt')->first();
        $vSmartphone = Vertical::where('slug', 'smartphone')->first();

        // Fetch Brands
        $brands = Brand::where('status', BrandStatus::Approved)->get()->keyBy('slug');

        $productsData = [
            // 1. Board Games (10 items)
            [
                'vertical' => $vBoardGame,
                'brand' => $brands['hasbro'] ?? $brands['generic'],
                'title' => 'Catan Trade Build Settle Strategy Board Game',
                'description' => 'Embark on a quest to settle the isle of Catan! Guide your settlers to victory by clever trading and cunning development.',
                'highlights' => ['For 3-4 players', 'Ages 10 and up', '60-90 minutes playtime', 'Award-winning modern classic'],
                'specs' => ['age_group' => '10-plus'],
                'image_key' => 'board-game',
                'variants' => [
                    ['attrs' => ['edition' => 'standard'], 'mrp' => 329900, 'price' => 249900, 'stock' => 25],
                    ['attrs' => ['edition' => 'deluxe'], 'mrp' => 499900, 'price' => 399900, 'stock' => 12],
                ],
            ],
            [
                'vertical' => $vBoardGame,
                'brand' => $brands['hasbro'] ?? $brands['generic'],
                'title' => 'Monopoly Classic Fast Dealing Property Trading Board Game',
                'description' => 'Own it all with the classic family favourite! Buy, sell, trade, and scheme your way to riches.',
                'highlights' => ['Fast-dealing property trading game', 'Includes 8 classic metal tokens', 'Ages 8 and up'],
                'specs' => ['age_group' => '7-plus'],
                'image_key' => 'board-game',
                'variants' => [
                    ['attrs' => ['edition' => 'standard'], 'mrp' => 149900, 'price' => 119900, 'stock' => 40],
                    ['attrs' => ['edition' => 'deluxe'], 'mrp' => 249900, 'price' => 199900, 'stock' => 15],
                ],
            ],
            [
                'vertical' => $vBoardGame,
                'brand' => $brands['mattel'] ?? $brands['generic'],
                'title' => 'Scrabble Deluxe Crossword Board Game with Rotating Board',
                'description' => 'The classic crossword word game that tests vocabulary, anagramming and strategy skills with rotating board and wooden tiles.',
                'highlights' => ['Rotating turntable board', 'Premium wooden tiles and racks', 'Ages 10 and up'],
                'specs' => ['age_group' => '10-plus'],
                'image_key' => 'board-game',
                'variants' => [
                    ['attrs' => ['edition' => 'standard'], 'mrp' => 129900, 'price' => 99900, 'stock' => 30],
                    ['attrs' => ['edition' => 'deluxe'], 'mrp' => 219900, 'price' => 169900, 'stock' => 8],
                ],
            ],
            [
                'vertical' => $vBoardGame,
                'brand' => $brands['generic'],
                'title' => 'Ticket to Ride Cross-Country Train Adventure Board Game',
                'description' => 'Compete with friends to claim railway routes connecting cities across North America.',
                'highlights' => ['2 to 5 players', 'Takes 30-60 minutes', 'High replayability'],
                'specs' => ['age_group' => '7-plus'],
                'image_key' => 'board-game',
                'variants' => [
                    ['attrs' => ['edition' => 'standard'], 'mrp' => 429900, 'price' => 329900, 'stock' => 18],
                ],
            ],
            [
                'vertical' => $vBoardGame,
                'brand' => $brands['hasbro'] ?? $brands['generic'],
                'title' => 'Clue Classic Mystery Suspect and Room Detective Game',
                'description' => 'Who committed the murder in the mansion? Eliminate suspects, discover the room and find the weapon to win.',
                'highlights' => ['Classic mystery whodunit', 'For 2-6 players', 'Great for game nights'],
                'specs' => ['age_group' => '7-plus'],
                'image_key' => 'board-game',
                'variants' => [
                    ['attrs' => ['edition' => 'standard'], 'mrp' => 129900, 'price' => 89900, 'stock' => 35],
                ],
            ],
            [
                'vertical' => $vBoardGame,
                'brand' => $brands['generic'],
                'title' => 'Pandemic Cooperative Strategy Save the World Board Game',
                'description' => 'You and your team of specialists must work together to treat disease hotspots while researching cures.',
                'highlights' => ['Cooperative gameplay', '2 to 4 players', 'Ages 10 and up'],
                'specs' => ['age_group' => '10-plus'],
                'image_key' => 'board-game',
                'variants' => [
                    ['attrs' => ['edition' => 'standard'], 'mrp' => 349900, 'price' => 279900, 'stock' => 22],
                    ['attrs' => ['edition' => 'collectors'], 'mrp' => 599900, 'price' => 499900, 'stock' => 5],
                ],
            ],
            [
                'vertical' => $vBoardGame,
                'brand' => $brands['hasbro'] ?? $brands['generic'],
                'title' => 'Battleship Classic Head-to-Head Naval Combat Strategy Game',
                'description' => 'Call your shot and fire! Track down enemy ships and sink them before yours are destroyed.',
                'highlights' => ['Portable battle cases', '2 players', 'Ages 7 and up'],
                'specs' => ['age_group' => '7-plus'],
                'image_key' => 'board-game',
                'variants' => [
                    ['attrs' => ['edition' => 'standard'], 'mrp' => 119900, 'price' => 79900, 'stock' => 0], // Out of stock
                ],
            ],
            [
                'vertical' => $vBoardGame,
                'brand' => $brands['mattel'] ?? $brands['generic'],
                'title' => 'Uno Extreme Card Game with Electronic Rapid Launcher',
                'description' => 'The thrilling twist on classic Uno where the card launcher spits out cards unexpectedly.',
                'highlights' => ['Electronic card shooter', 'Sound effects', '2 to 10 players'],
                'specs' => ['age_group' => '7-plus'],
                'image_key' => 'board-game',
                'variants' => [
                    ['attrs' => ['edition' => 'standard'], 'mrp' => 199900, 'price' => 149900, 'stock' => 28],
                ],
            ],
            [
                'vertical' => $vBoardGame,
                'brand' => $brands['hasbro'] ?? $brands['generic'],
                'title' => 'Jenga Classic Genuine Hardwood Stacking Tower Game',
                'description' => 'Pull out a block without crashing the stack to win at Jenga! It takes skill, strategy, and luck.',
                'highlights' => ['54 precision crafted hardwood blocks', 'Stacking sleeve included', 'Fun for all ages'],
                'specs' => ['age_group' => '7-plus'],
                'image_key' => 'board-game',
                'variants' => [
                    ['attrs' => ['edition' => 'standard'], 'mrp' => 99900, 'price' => 69900, 'stock' => 50],
                ],
            ],
            [
                'vertical' => $vBoardGame,
                'brand' => $brands['hasbro'] ?? $brands['generic'],
                'title' => 'Risk Global Domination Conquest Military Strategy Game',
                'description' => 'Command your army and lead your troops to global conquest in the iconic tactical military board game.',
                'highlights' => ['Detailed army miniatures', 'For 2 to 5 players', 'Ages 10 and up'],
                'specs' => ['age_group' => '10-plus'],
                'image_key' => 'board-game',
                'variants' => [
                    ['attrs' => ['edition' => 'standard'], 'mrp' => 299900, 'price' => 219900, 'stock' => 16],
                    ['attrs' => ['edition' => 'deluxe'], 'mrp' => 449900, 'price' => 349900, 'stock' => 7],
                ],
            ],

            // 2. Action Figures (10 items)
            [
                'vertical' => $vActionFigure,
                'brand' => $brands['hasbro'] ?? $brands['generic'],
                'title' => 'Marvel Legends Iron Man Mark 85 Articulated Action Figure',
                'description' => 'Tony Stark suits up in the cutting-edge Mark 85 armor. Highly poseable 6-inch action figure with alternate hands and repulsor blasts.',
                'highlights' => ['Marvel Cinematic Universe inspired', 'Over 20 points of articulation', 'Includes 4 accessories'],
                'specs' => ['material' => 'plastic'],
                'image_key' => 'action-figure',
                'variants' => [
                    ['attrs' => ['character' => 'iron-man'], 'mrp' => 249900, 'price' => 199900, 'stock' => 25],
                ],
            ],
            [
                'vertical' => $vActionFigure,
                'brand' => $brands['hasbro'] ?? $brands['generic'],
                'title' => 'Marvel Legends Spider-Man Integrated Suit Collectible Figure',
                'description' => 'Peter Parker swings into battle in his integrated suit with web-slinging action and dynamic articulation.',
                'highlights' => ['Highly detailed sculpt', 'Includes alternate web blast hands', 'Collector grade packaging'],
                'specs' => ['material' => 'plastic'],
                'image_key' => 'action-figure',
                'variants' => [
                    ['attrs' => ['character' => 'spider-man'], 'mrp' => 229900, 'price' => 179900, 'stock' => 30],
                ],
            ],
            [
                'vertical' => $vActionFigure,
                'brand' => $brands['mattel'] ?? $brands['generic'],
                'title' => 'DC Multiverse Batman Dark Knight Heavy Armored Figure',
                'description' => 'The Caped Crusader stands ready to defend Gotham in his heavy armored combat suit.',
                'highlights' => ['Includes Batarang and grapple launcher', 'Fabric cape', 'Ultra-articulated joints'],
                'specs' => ['material' => 'die-cast'],
                'image_key' => 'action-figure',
                'variants' => [
                    ['attrs' => ['character' => 'batman'], 'mrp' => 299900, 'price' => 239900, 'stock' => 14],
                ],
            ],
            [
                'vertical' => $vActionFigure,
                'brand' => $brands['hasbro'] ?? $brands['generic'],
                'title' => 'Star Wars The Black Series Darth Vader Premium Action Figure',
                'description' => 'Embrace the power of the dark side with Darth Vader in exquisite 6-inch scale detail with red lightsaber.',
                'highlights' => ['Photo-real face deco', 'Cloth cape and tunic', 'Includes lightsaber and hilt'],
                'specs' => ['material' => 'plastic'],
                'image_key' => 'action-figure',
                'variants' => [
                    ['attrs' => ['character' => 'darth-vader'], 'mrp' => 329900, 'price' => 279900, 'stock' => 20],
                ],
            ],
            [
                'vertical' => $vActionFigure,
                'brand' => $brands['funko'] ?? $brands['generic'],
                'title' => 'Funko Pop! Marvel Avengers Iron Man Metallic Bobble-Head',
                'description' => 'Stylized collectible Iron Man vinyl figure standing 3.75 inches tall with window display box.',
                'highlights' => ['Metallic finish', 'Official Marvel licensed', 'Stands 3.75 inches tall'],
                'specs' => ['material' => 'vinyl'],
                'image_key' => 'action-figure',
                'variants' => [
                    ['attrs' => ['character' => 'iron-man'], 'mrp' => 149900, 'price' => 119900, 'stock' => 35],
                ],
            ],
            [
                'vertical' => $vActionFigure,
                'brand' => $brands['funko'] ?? $brands['generic'],
                'title' => 'Funko Pop! Star Wars Darth Vader Dark Lord Vinyl Bobble-Head',
                'description' => 'The iconic Sith Lord rendered in iconic Funko Pop vinyl bobblehead format.',
                'highlights' => ['Collector window display box', 'Classic stance with red lightsaber', 'Must-have for fans'],
                'specs' => ['material' => 'vinyl'],
                'image_key' => 'action-figure',
                'variants' => [
                    ['attrs' => ['character' => 'darth-vader'], 'mrp' => 159900, 'price' => 129900, 'stock' => 22],
                ],
            ],
            [
                'vertical' => $vActionFigure,
                'brand' => $brands['funko'] ?? $brands['generic'],
                'title' => 'Funko Pop! Heroes DC Batman 80th Anniversary Vinyl Figure',
                'description' => 'Commemorate 80 years of Batman with this exclusive black suit stylized figurine.',
                'highlights' => ['80th Anniversary seal', 'Stylized vinyl finish', 'Matte finish box'],
                'specs' => ['material' => 'vinyl'],
                'image_key' => 'action-figure',
                'variants' => [
                    ['attrs' => ['character' => 'batman'], 'mrp' => 149900, 'price' => 109900, 'stock' => 0], // Out of stock
                ],
            ],
            [
                'vertical' => $vActionFigure,
                'brand' => $brands['nerf'] ?? $brands['generic'],
                'title' => 'Nerf Elite 2.0 Commander RD-6 Rotating Drum Dart Blaster',
                'description' => 'Blast into battle with the Nerf Commander featuring a 6-dart rotating drum and tactical rails.',
                'highlights' => ['6-dart rotating drum', 'Includes 12 official Nerf Elite darts', 'Fires up to 90 feet'],
                'specs' => ['material' => 'plastic'],
                'image_key' => 'action-figure',
                'variants' => [
                    ['attrs' => ['character' => 'iron-man'], 'mrp' => 179900, 'price' => 139900, 'stock' => 45],
                ],
            ],
            [
                'vertical' => $vActionFigure,
                'brand' => $brands['nerf'] ?? $brands['generic'],
                'title' => 'Nerf Ultra One Motorized High Performance Blaster',
                'description' => 'Motorized Nerf Ultra blasting with extreme distance, accuracy, and revolutionary dart flight.',
                'highlights' => ['25-dart high capacity drum', 'Fires darts up to 120 feet', 'Fast-firing motorized action'],
                'specs' => ['material' => 'plastic'],
                'image_key' => 'action-figure',
                'variants' => [
                    ['attrs' => ['character' => 'spider-man'], 'mrp' => 599900, 'price' => 469900, 'stock' => 12],
                ],
            ],
            [
                'vertical' => $vActionFigure,
                'brand' => $brands['hot-wheels'] ?? $brands['generic'],
                'title' => 'Hot Wheels 10-Car Die-Cast Vehicle Collector Pack',
                'description' => 'Accelerate into an instant Hot Wheels collection with a 10-pack of 1:64 scale die-cast cars.',
                'highlights' => ['1:64 scale die-cast metal', '10 unique vehicle designs', 'Smooth rolling wheels'],
                'specs' => ['material' => 'die-cast'],
                'image_key' => 'action-figure',
                'variants' => [
                    ['attrs' => ['character' => 'batman'], 'mrp' => 159900, 'price' => 129900, 'stock' => 50],
                ],
            ],

            // 3. Building Blocks (10 items)
            [
                'vertical' => $vBuildingBlocks,
                'brand' => $brands['lego'] ?? $brands['generic'],
                'title' => 'LEGO Star Wars Millennium Falcon Legendary Starship Model Kit',
                'description' => 'Join Finn, Chewbacca, Lando Calrissian and Boolio aboard the legendary Millennium Falcon starship.',
                'highlights' => ['1351 authentic building pieces', 'Includes 7 Star Wars characters', 'Rotating gun turrets and cockpit'],
                'specs' => ['piece_count' => 1351],
                'image_key' => 'building-blocks',
                'variants' => [
                    ['attrs' => ['theme' => 'space'], 'mrp' => 1699900, 'price' => 1449900, 'stock' => 15],
                ],
            ],
            [
                'vertical' => $vBuildingBlocks,
                'brand' => $brands['lego'] ?? $brands['generic'],
                'title' => 'LEGO Icons NASA Space Shuttle Discovery Model Set',
                'description' => 'Celebrate the wonders of space exploration with this incredibly detailed NASA Space Shuttle Discovery and Hubble Telescope.',
                'highlights' => ['2354 precision pieces', 'Functional payload bay doors', 'Retractable landing gear'],
                'specs' => ['piece_count' => 2354],
                'image_key' => 'building-blocks',
                'variants' => [
                    ['attrs' => ['theme' => 'space'], 'mrp' => 2199900, 'price' => 1849900, 'stock' => 8],
                ],
            ],
            [
                'vertical' => $vBuildingBlocks,
                'brand' => $brands['lego'] ?? $brands['generic'],
                'title' => 'LEGO City Police Station Multi-Level Jailbreak Set',
                'description' => 'Catch the crooks with the 3-level LEGO City Police Station featuring patrol car, helicopter, and garbage truck.',
                'highlights' => ['668 building pieces', '5 police and crook minifigures', 'Breakout jail cell function'],
                'specs' => ['piece_count' => 668],
                'image_key' => 'building-blocks',
                'variants' => [
                    ['attrs' => ['theme' => 'city'], 'mrp' => 899900, 'price' => 749900, 'stock' => 25],
                ],
            ],
            [
                'vertical' => $vBuildingBlocks,
                'brand' => $brands['lego'] ?? $brands['generic'],
                'title' => 'LEGO Superheroes Batmobile Tumbler Armored Vehicle',
                'description' => 'Build and display one of the most cinema-iconic vehicles: the Tumbler Batmobile from The Dark Knight trilogy.',
                'highlights' => ['2049 intricate pieces', 'Includes Batman and The Joker minifigures', 'Sturdy display base'],
                'specs' => ['piece_count' => 2049],
                'image_key' => 'building-blocks',
                'variants' => [
                    ['attrs' => ['theme' => 'superheroes'], 'mrp' => 2699900, 'price' => 2299900, 'stock' => 6],
                ],
            ],
            [
                'vertical' => $vBuildingBlocks,
                'brand' => $brands['lego'] ?? $brands['generic'],
                'title' => 'LEGO Marvel Avengers Tower Monumental Display Set',
                'description' => 'Recreate the most iconic battles of the Marvel Universe in this spectacular 90cm tall Avengers Tower build.',
                'highlights' => ['5201 pieces', 'Includes 31 Marvel minifigures', 'Removable roof and floors for interior play'],
                'specs' => ['piece_count' => 5201],
                'image_key' => 'building-blocks',
                'variants' => [
                    ['attrs' => ['theme' => 'superheroes'], 'mrp' => 4999900, 'price' => 4499900, 'stock' => 4],
                ],
            ],
            [
                'vertical' => $vBuildingBlocks,
                'brand' => $brands['lego'] ?? $brands['generic'],
                'title' => 'LEGO Icons Lion Knights Medieval Fortress Castle',
                'description' => 'A masterpiece build celebrating 90 years of LEGO history with working drawbridge, hidden passages and castle walls.',
                'highlights' => ['4514 building pieces', '22 medieval minifigures', 'Opens up for comprehensive interior access'],
                'specs' => ['piece_count' => 4514],
                'image_key' => 'building-blocks',
                'variants' => [
                    ['attrs' => ['theme' => 'castle'], 'mrp' => 3999900, 'price' => 3499900, 'stock' => 5],
                ],
            ],
            [
                'vertical' => $vBuildingBlocks,
                'brand' => $brands['lego'] ?? $brands['generic'],
                'title' => 'LEGO City Downtown Fire Station Rescue Playset',
                'description' => 'Sound the alarm! The fire station team is ready with a modern command center, drone, and water-shooting truck.',
                'highlights' => ['540 building pieces', 'Includes fire chief and firefighters', 'Shoots water dart elements'],
                'specs' => ['piece_count' => 540],
                'image_key' => 'building-blocks',
                'variants' => [
                    ['attrs' => ['theme' => 'city'], 'mrp' => 699900, 'price' => 579900, 'stock' => 30],
                ],
            ],
            [
                'vertical' => $vBuildingBlocks,
                'brand' => $brands['lego'] ?? $brands['generic'],
                'title' => 'LEGO Technic Supercar SP3 1:8 Scale Masterpiece Build',
                'description' => 'Experience automotive excellence with working paddle-shift gearbox, V12 engine with moving pistons, and butterfly doors.',
                'highlights' => ['3778 technical pieces', 'Functioning 8-speed sequential gearbox', '1:8 scale supercar authentic replica'],
                'specs' => ['piece_count' => 3778],
                'image_key' => 'building-blocks',
                'variants' => [
                    ['attrs' => ['theme' => 'city'], 'mrp' => 4499900, 'price' => 3899900, 'stock' => 3],
                ],
            ],
            [
                'vertical' => $vBuildingBlocks,
                'brand' => $brands['lego'] ?? $brands['generic'],
                'title' => 'LEGO Creator 3in1 Space Shuttle Galactic Explorer',
                'description' => 'Blast off into orbit with a 3in1 build that transforms into a space shuttle, astronaut figure, or futuristic spaceship.',
                'highlights' => ['3in1 creative construction', '144 elements', 'Ages 6 and up'],
                'specs' => ['piece_count' => 144],
                'image_key' => 'building-blocks',
                'variants' => [
                    ['attrs' => ['theme' => 'space'], 'mrp' => 149900, 'price' => 119900, 'stock' => 0], // Out of stock
                ],
            ],
            [
                'vertical' => $vBuildingBlocks,
                'brand' => $brands['lego'] ?? $brands['generic'],
                'title' => 'LEGO Classic Large Creative Transparent Brick Box',
                'description' => 'Unleash limitless creativity with a huge variety of LEGO bricks in 33 different colors with windows, doors, and eyes.',
                'highlights' => ['790 colorful bricks', 'Convenient plastic storage box', 'Ideal starter set for all builders'],
                'specs' => ['piece_count' => 790],
                'image_key' => 'building-blocks',
                'variants' => [
                    ['attrs' => ['theme' => 'city'], 'mrp' => 499900, 'price' => 399900, 'stock' => 40],
                ],
            ],

            // 4. Fashion / T-Shirts (10 items)
            [
                'vertical' => $vTshirt,
                'brand' => $brands['nike'] ?? $brands['generic'],
                'title' => 'Nike Dri-FIT Men Performance Athletic Training T-Shirt',
                'description' => 'Engineered with sweat-wicking technology to keep you dry and comfortable through your most grueling workout sessions.',
                'highlights' => ['Dri-FIT technology', '100% Recycled polyester', 'Standard fit for a relaxed feel'],
                'specs' => ['fabric' => 'polyester', 'sleeve' => 'half-sleeve'],
                'image_key' => 't-shirt',
                'variants' => [
                    ['attrs' => ['size' => 'm', 'color' => 'black'], 'mrp' => 179500, 'price' => 149500, 'stock' => 50],
                    ['attrs' => ['size' => 'l', 'color' => 'black'], 'mrp' => 179500, 'price' => 149500, 'stock' => 35],
                    ['attrs' => ['size' => 'm', 'color' => 'navy-blue'], 'mrp' => 179500, 'price' => 139500, 'stock' => 20],
                ],
            ],
            [
                'vertical' => $vTshirt,
                'brand' => $brands['nike'] ?? $brands['generic'],
                'title' => 'Nike Sportswear Club Pure Cotton Everyday Crew T-Shirt',
                'description' => 'Made with everyday lightweight cotton and a classic fit for a soft, familiar feel right out of the box.',
                'highlights' => ['100% Soft cotton jersey', 'Embroidered Nike Futura logo', 'Ribbed crew collar'],
                'specs' => ['fabric' => 'cotton', 'sleeve' => 'half-sleeve'],
                'image_key' => 't-shirt',
                'variants' => [
                    ['attrs' => ['size' => 's', 'color' => 'white'], 'mrp' => 149500, 'price' => 119500, 'stock' => 30],
                    ['attrs' => ['size' => 'm', 'color' => 'white'], 'mrp' => 149500, 'price' => 119500, 'stock' => 40],
                    ['attrs' => ['size' => 'l', 'color' => 'red'], 'mrp' => 149500, 'price' => 119500, 'stock' => 25],
                ],
            ],
            [
                'vertical' => $vTshirt,
                'brand' => $brands['puma'] ?? $brands['generic'],
                'title' => 'Puma Men Graphic Front Cat Logo Pure Cotton T-Shirt',
                'description' => 'Sporty everyday cotton t-shirt with bold Puma graphic branding across the chest.',
                'highlights' => ['Regular comfortable fit', 'Soft breathable cotton', 'Machine washable'],
                'specs' => ['fabric' => 'cotton', 'sleeve' => 'half-sleeve'],
                'image_key' => 't-shirt',
                'variants' => [
                    ['attrs' => ['size' => 'm', 'color' => 'black'], 'mrp' => 119900, 'price' => 79900, 'stock' => 45],
                    ['attrs' => ['size' => 'l', 'color' => 'black'], 'mrp' => 119900, 'price' => 79900, 'stock' => 35],
                ],
            ],
            [
                'vertical' => $vTshirt,
                'brand' => $brands['puma'] ?? $brands['generic'],
                'title' => 'Puma Essentials Solid Ribbed Crew Neck Casual T-Shirt',
                'description' => 'Timeless minimalist casual t-shirt crafted with sustainable cotton blend.',
                'highlights' => ['Cotton blend jersey', 'Subtle chest cat embroidery', 'Shape-retaining ribbed collar'],
                'specs' => ['fabric' => 'cotton-blend', 'sleeve' => 'half-sleeve'],
                'image_key' => 't-shirt',
                'variants' => [
                    ['attrs' => ['size' => 'm', 'color' => 'navy-blue'], 'mrp' => 99900, 'price' => 69900, 'stock' => 60],
                    ['attrs' => ['size' => 'xl', 'color' => 'navy-blue'], 'mrp' => 99900, 'price' => 69900, 'stock' => 25],
                ],
            ],
            [
                'vertical' => $vTshirt,
                'brand' => $brands['generic'],
                'title' => 'Generic Heavyweight Streetwear Drop Shoulder Oversized T-Shirt',
                'description' => 'Boxy modern streetwear silhouette made of thick 240 GSM organic cotton fabric.',
                'highlights' => ['240 GSM heavy cotton', 'Relaxed drop-shoulder cut', 'Pre-shrunk fabric'],
                'specs' => ['fabric' => 'cotton', 'sleeve' => 'half-sleeve'],
                'image_key' => 't-shirt',
                'variants' => [
                    ['attrs' => ['size' => 'm', 'color' => 'black'], 'mrp' => 89900, 'price' => 54900, 'stock' => 40],
                    ['attrs' => ['size' => 'l', 'color' => 'white'], 'mrp' => 89900, 'price' => 54900, 'stock' => 30],
                ],
            ],
            [
                'vertical' => $vTshirt,
                'brand' => $brands['generic'],
                'title' => 'Generic Essential Organic Cotton Everyday Plain T-Shirt',
                'description' => 'Budget-friendly basic daily tee crafted from soft combed cotton yarns.',
                'highlights' => ['100% Combed cotton', 'Double needle hem stitching', 'Tagless comfort'],
                'specs' => ['fabric' => 'cotton', 'sleeve' => 'half-sleeve'],
                'image_key' => 't-shirt',
                'variants' => [
                    ['attrs' => ['size' => 'm', 'color' => 'white'], 'mrp' => 49900, 'price' => 29900, 'stock' => 70],
                ],
            ],
            [
                'vertical' => $vTshirt,
                'brand' => $brands['nike'] ?? $brands['generic'],
                'title' => 'Nike Pro Men Long Sleeve Compression Layer Training Top',
                'description' => 'Body-hugging compression base layer that supports muscles and moves naturally during high-intensity training.',
                'highlights' => ['Nike Pro compression fit', 'Full sleeves with thumb loops', 'Flatlock anti-chafing seams'],
                'specs' => ['fabric' => 'polyester', 'sleeve' => 'full-sleeve'],
                'image_key' => 't-shirt',
                'variants' => [
                    ['attrs' => ['size' => 'l', 'color' => 'black'], 'mrp' => 279500, 'price' => 219500, 'stock' => 18],
                ],
            ],
            [
                'vertical' => $vTshirt,
                'brand' => $brands['puma'] ?? $brands['generic'],
                'title' => 'Puma Performance Lightweight Sleeveless Running Tank Top',
                'description' => 'Ultra-breathable athletic singlet designed for runners in warm weather with reflective detailing.',
                'highlights' => ['dryCELL moisture control', 'Sleeveless ergonomic design', 'Reflective visibility accents'],
                'specs' => ['fabric' => 'polyester', 'sleeve' => 'sleeveless'],
                'image_key' => 't-shirt',
                'variants' => [
                    ['attrs' => ['size' => 'm', 'color' => 'red'], 'mrp' => 129900, 'price' => 89900, 'stock' => 0], // Out of stock
                ],
            ],
            [
                'vertical' => $vTshirt,
                'brand' => $brands['generic'],
                'title' => 'Generic Vintage Washed Distressed Grunge Graphic T-Shirt',
                'description' => 'Stone washed cotton vintage tee with retro typography and distressed edge ribbing.',
                'highlights' => ['Mineral acid wash finish', 'Soft handfeel', 'Streetwear aesthetic'],
                'specs' => ['fabric' => 'cotton', 'sleeve' => 'half-sleeve'],
                'image_key' => 't-shirt',
                'variants' => [
                    ['attrs' => ['size' => 'l', 'color' => 'black'], 'mrp' => 99900, 'price' => 64900, 'stock' => 22],
                ],
            ],
            [
                'vertical' => $vTshirt,
                'brand' => $brands['nike'] ?? $brands['generic'],
                'title' => 'Nike Sportswear Repeat Logo Tape Sleeve Cotton T-Shirt',
                'description' => 'Modern athletic look featuring repetitive Nike swoosh taping running down both sleeves.',
                'highlights' => ['Repeating logo tape sleeves', 'Heavyweight cotton fabric', 'Loose comfortable cut'],
                'specs' => ['fabric' => 'cotton', 'sleeve' => 'half-sleeve'],
                'image_key' => 't-shirt',
                'variants' => [
                    ['attrs' => ['size' => 'xl', 'color' => 'white'], 'mrp' => 229500, 'price' => 179500, 'stock' => 16],
                ],
            ],

            // 5. Electronics / Smartphones (8 items)
            [
                'vertical' => $vSmartphone,
                'brand' => $brands['apple'] ?? $brands['generic'],
                'title' => 'Apple iPhone 13 Flagship A15 Bionic Smartphone',
                'description' => 'Super Retina XDR display with durable flat-edge design, A15 Bionic chip and advanced dual-camera system with Cinematic mode.',
                'highlights' => ['6.1-inch Super Retina XDR OLED', 'A15 Bionic lightning-fast processor', 'Ceramic Shield front glass', 'IP68 water resistance'],
                'specs' => ['battery_capacity' => 3240, 'screen_size' => 6.1],
                'image_key' => 'smartphone',
                'variants' => [
                    ['attrs' => ['ram' => '4gb', 'storage' => '128gb', 'color' => 'midnight-black'], 'mrp' => 5990000, 'price' => 4999900, 'stock' => 25],
                    ['attrs' => ['ram' => '4gb', 'storage' => '256gb', 'color' => 'midnight-black'], 'mrp' => 6990000, 'price' => 5899900, 'stock' => 15],
                    ['attrs' => ['ram' => '4gb', 'storage' => '128gb', 'color' => 'starlight'], 'mrp' => 5990000, 'price' => 4999900, 'stock' => 18],
                ],
            ],
            [
                'vertical' => $vSmartphone,
                'brand' => $brands['apple'] ?? $brands['generic'],
                'title' => 'Apple iPhone 14 Dual 12MP Camera Photonic Engine 5G Smartphone',
                'description' => 'Impressive battery life, Photonic Engine for low-light photography, Crash Detection, and smooth iOS experience.',
                'highlights' => ['6.1-inch OLED display', 'Crash Detection safety feature', 'All-day battery life'],
                'specs' => ['battery_capacity' => 3279, 'screen_size' => 6.1],
                'image_key' => 'smartphone',
                'variants' => [
                    ['attrs' => ['ram' => '6gb', 'storage' => '128gb', 'color' => 'midnight-black'], 'mrp' => 6990000, 'price' => 5999900, 'stock' => 30],
                    ['attrs' => ['ram' => '6gb', 'storage' => '256gb', 'color' => 'ocean-blue'], 'mrp' => 7990000, 'price' => 6899900, 'stock' => 12],
                ],
            ],
            [
                'vertical' => $vSmartphone,
                'brand' => $brands['apple'] ?? $brands['generic'],
                'title' => 'Apple iPhone 15 Dynamic Island 48MP Main Camera Smartphone',
                'description' => 'Dynamic Island bubbles up alerts and Live Activities. 48MP Main camera with 2x Telephoto. Color-infused glass and aluminum design.',
                'highlights' => ['Dynamic Island interactive interface', '48MP Main Camera with 2x zoom', 'USB-C Universal Charging connector'],
                'specs' => ['battery_capacity' => 3349, 'screen_size' => 6.1],
                'image_key' => 'smartphone',
                'variants' => [
                    ['attrs' => ['ram' => '6gb', 'storage' => '128gb', 'color' => 'starlight'], 'mrp' => 7990000, 'price' => 6999900, 'stock' => 20],
                    ['attrs' => ['ram' => '6gb', 'storage' => '256gb', 'color' => 'ocean-blue'], 'mrp' => 8990000, 'price' => 7899900, 'stock' => 10],
                ],
            ],
            [
                'vertical' => $vSmartphone,
                'brand' => $brands['apple'] ?? $brands['generic'],
                'title' => 'Apple iPhone SE 3rd Generation Compact Power 5G Smartphone',
                'description' => 'Compact 4.7-inch design powered by the high-speed A15 Bionic chip and fast 5G cellular speed.',
                'highlights' => ['A15 Bionic powerhouse performance', 'Touch ID home button security', 'Pocket-friendly durable design'],
                'specs' => ['battery_capacity' => 2018, 'screen_size' => 4.7],
                'image_key' => 'smartphone',
                'variants' => [
                    ['attrs' => ['ram' => '4gb', 'storage' => '64gb', 'color' => 'midnight-black'], 'mrp' => 4790000, 'price' => 4199900, 'stock' => 0], // Out of stock
                ],
            ],
            [
                'vertical' => $vSmartphone,
                'brand' => $brands['samsung'] ?? $brands['generic'],
                'title' => 'Samsung Galaxy S23 FE 5G Dynamic AMOLED 120Hz Smartphone',
                'description' => 'Epic moments made accessible. Pro-grade triple camera with Nightography, Dynamic AMOLED 2X display, and long-lasting battery.',
                'highlights' => ['6.4-inch Dynamic AMOLED 120Hz display', '50MP High-resolution camera', 'Armor Aluminum frame'],
                'specs' => ['battery_capacity' => 4500, 'screen_size' => 6.4],
                'image_key' => 'smartphone',
                'variants' => [
                    ['attrs' => ['ram' => '8gb', 'storage' => '128gb', 'color' => 'midnight-black'], 'mrp' => 4999900, 'price' => 3899900, 'stock' => 35],
                    ['attrs' => ['ram' => '8gb', 'storage' => '256gb', 'color' => 'ocean-blue'], 'mrp' => 5499900, 'price' => 4399900, 'stock' => 20],
                ],
            ],
            [
                'vertical' => $vSmartphone,
                'brand' => $brands['samsung'] ?? $brands['generic'],
                'title' => 'Samsung Galaxy S24 Galaxy AI Flagship Compact Smartphone',
                'description' => 'Meet Galaxy S24 with next-level Galaxy AI features: Circle to Search, Live Call Translate, and Note Assist.',
                'highlights' => ['Galaxy AI intelligent features', '50MP camera with AI ISP engine', 'Enhanced cooling chamber'],
                'specs' => ['battery_capacity' => 4000, 'screen_size' => 6.2],
                'image_key' => 'smartphone',
                'variants' => [
                    ['attrs' => ['ram' => '8gb', 'storage' => '256gb', 'color' => 'starlight'], 'mrp' => 7999900, 'price' => 7299900, 'stock' => 15],
                    ['attrs' => ['ram' => '8gb', 'storage' => '512gb', 'color' => 'midnight-black'], 'mrp' => 8999900, 'price' => 8299900, 'stock' => 8],
                ],
            ],
            [
                'vertical' => $vSmartphone,
                'brand' => $brands['samsung'] ?? $brands['generic'],
                'title' => 'Samsung Galaxy A55 5G Metal Frame Super AMOLED Smartphone',
                'description' => 'Metal frame beauty meets smart protection with Samsung Knox Vault, optical image stabilization and Vivid Nightography.',
                'highlights' => ['Corning Gorilla Glass Victus+ front and back', 'IP67 dust and water resistance', '5000 mAh 2-day battery'],
                'specs' => ['battery_capacity' => 5000, 'screen_size' => 6.6],
                'image_key' => 'smartphone',
                'variants' => [
                    ['attrs' => ['ram' => '8gb', 'storage' => '128gb', 'color' => 'ocean-blue'], 'mrp' => 3999900, 'price' => 3499900, 'stock' => 40],
                ],
            ],
            [
                'vertical' => $vSmartphone,
                'brand' => $brands['samsung'] ?? $brands['generic'],
                'title' => 'Samsung Galaxy M34 5G Monster 6000mAh Battery Smartphone',
                'description' => 'Massive 6000mAh battery paired with smooth 120Hz Super AMOLED display and 50MP No Shake Camera.',
                'highlights' => ['Monster 6000mAh battery backup', '50MP No Shake OIS Camera', '120Hz Super AMOLED screen'],
                'specs' => ['battery_capacity' => 6000, 'screen_size' => 6.5],
                'image_key' => 'smartphone',
                'variants' => [
                    ['attrs' => ['ram' => '6gb', 'storage' => '128gb', 'color' => 'midnight-black'], 'mrp' => 2449900, 'price' => 1699900, 'stock' => 55],
                ],
            ],
        ];

        $productIndex = 0;
        foreach ($productsData as $data) {
            $productIndex++;
            $vertical = $data['vertical'];
            $brand = $data['brand'];

            if (! $vertical || ! $brand) {
                continue;
            }

            $product = Product::firstOrCreate(
                [
                    'slug' => Str::slug($brand->name.' '.$data['title']),
                ],
                [
                    'vertical_id' => $vertical->id,
                    'brand_id' => $brand->id,
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'highlights' => $data['highlights'],
                    'specs' => $data['specs'],
                    'created_by' => $primarySeller->id,
                    'qc_status' => ProductQcStatus::Approved,
                    'reviewed_at' => now(),
                ]
            );

            // Create Primary Product Image
            $imgPath = $imagePaths[$data['image_key']] ?? $imagePaths['board-game'];
            ProductImage::firstOrCreate(
                [
                    'product_id' => $product->id,
                    'sort_order' => 1,
                ],
                [
                    'path' => $imgPath,
                    'is_primary' => true,
                    'status' => ProductImageStatus::Live,
                ]
            );

            // Create Variants & Listings
            foreach ($data['variants'] as $vIdx => $vData) {
                $variantKey = VariantKeyNormalizer::normalize($vData['attrs']);

                $variant = ProductVariant::firstOrCreate(
                    [
                        'product_id' => $product->id,
                        'variant_key' => $variantKey,
                    ],
                    [
                        'upid' => UpidGenerator::generate(),
                        'variant_attributes' => $vData['attrs'],
                        'barcode' => '890'.str_pad((string) ($product->id * 100 + $vIdx), 10, '0', STR_PAD_LEFT),
                        'weight' => 0.5,
                        'dimensions' => ['length' => 20, 'width' => 15, 'height' => 5],
                        'qc_status' => VariantQcStatus::Approved,
                    ]
                );

                // Primary Listing
                Listing::firstOrCreate(
                    [
                        'seller_profile_id' => $primarySeller->id,
                        'product_variant_id' => $variant->id,
                    ],
                    [
                        'sku' => 'KHIL-'.strtoupper(Str::slug($brand->slug))."-{$product->id}-{$vIdx}-A",
                        'mrp' => $vData['mrp'],
                        'selling_price' => $vData['price'],
                        'stock' => $vData['stock'],
                        'status' => ListingStatus::Active,
                    ]
                );

                // For some items (every 4th product), add competing secondary seller listing to test buy-box choice
                if ($productIndex % 4 === 0 && $secondarySeller->id !== $primarySeller->id && $vData['stock'] > 0) {
                    // Secondary seller listing with higher price
                    $competingPrice = min($vData['mrp'], (int) ($vData['price'] * 1.08));
                    Listing::firstOrCreate(
                        [
                            'seller_profile_id' => $secondarySeller->id,
                            'product_variant_id' => $variant->id,
                        ],
                        [
                            'sku' => 'GALAXY-'.strtoupper(Str::slug($brand->slug))."-{$product->id}-{$vIdx}-B",
                            'mrp' => $vData['mrp'],
                            'selling_price' => $competingPrice,
                            'stock' => 10,
                            'status' => ListingStatus::Active,
                        ]
                    );
                }
            }
        }

        // Refresh all buy box calculations
        app(ProductBuyBoxService::class)->refreshAll();
    }

    /**
     * Ensure dummy local SVG placeholder images exist in public storage.
     *
     * @return array<string, string> Keyed by vertical type
     */
    protected function ensurePlaceholderImages(): array
    {
        $configs = [
            'board-game' => ['#e0f2fe', '#0369a1', 'Board Game'],
            'action-figure' => ['#fef3c7', '#b45309', 'Action Figure'],
            'building-blocks' => ['#fee2e2', '#b91c1c', 'Building Blocks'],
            't-shirt' => ['#f3e8ff', '#7e22ce', 'Fashion T-Shirt'],
            'smartphone' => ['#e0e7ff', '#4338ca', 'Smartphone'],
        ];

        $paths = [];
        foreach ($configs as $key => [$bg, $fg, $label]) {
            $path = "products/{$key}.svg";
            if (! Storage::disk('public')->exists($path)) {
                $svg = sprintf(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600" viewBox="0 0 600 600">'
                    .'<rect width="600" height="600" fill="%s" rx="16"/>'
                    .'<circle cx="300" cy="240" r="100" fill="%s" opacity="0.15"/>'
                    .'<text x="300" y="255" font-family="system-ui, -apple-system, sans-serif" font-size="44" font-weight="bold" fill="%s" text-anchor="middle">%s</text>'
                    .'<text x="300" y="380" font-family="system-ui, -apple-system, sans-serif" font-size="22" fill="%s" text-anchor="middle" opacity="0.75">Khilauna Toys</text>'
                    .'</svg>',
                    $bg, $fg, $fg, htmlspecialchars($label), $fg
                );
                Storage::disk('public')->put($path, $svg);
            }
            $paths[$key] = $path;
        }

        return $paths;
    }
}
