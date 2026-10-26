<?php

namespace Database\Seeders;

use App\Enums\AttributeType;
use App\Enums\BrandStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Vertical;
use App\Models\VerticalAttribute;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Seed Approved Brands
        $brands = [
            ['slug' => 'generic', 'name' => 'Generic'],
            ['slug' => 'lego', 'name' => 'LEGO'],
            ['slug' => 'hasbro', 'name' => 'Hasbro'],
            ['slug' => 'mattel', 'name' => 'Mattel'],
            ['slug' => 'funko', 'name' => 'Funko'],
            ['slug' => 'nerf', 'name' => 'Nerf'],
            ['slug' => 'hot-wheels', 'name' => 'Hot Wheels'],
            ['slug' => 'nike', 'name' => 'Nike'],
            ['slug' => 'puma', 'name' => 'Puma'],
            ['slug' => 'apple', 'name' => 'Apple'],
            ['slug' => 'samsung', 'name' => 'Samsung'],
        ];

        foreach ($brands as $b) {
            Brand::firstOrCreate(
                ['slug' => $b['slug']],
                [
                    'name' => $b['name'],
                    'status' => BrandStatus::Approved,
                ]
            );
        }

        // 2. Tree 1: Fashion › Men › Topwear › T-Shirt
        $fashion = Category::firstOrCreate(
            ['slug' => 'fashion'],
            ['name' => 'Fashion', 'sort_order' => 1, 'is_active' => true]
        );

        $men = Category::firstOrCreate(
            ['slug' => 'men', 'parent_id' => $fashion->id],
            ['name' => 'Men', 'sort_order' => 1, 'is_active' => true]
        );

        $topwear = Category::firstOrCreate(
            ['slug' => 'topwear', 'parent_id' => $men->id],
            ['name' => 'Topwear', 'sort_order' => 1, 'is_active' => true]
        );

        $tshirt = Vertical::firstOrCreate(
            ['slug' => 't-shirt'],
            [
                'category_id' => $topwear->id,
                'name' => 'T-Shirt',
                'hsn_code' => '6109',
                'gst_rate' => 5.00,
                'is_active' => true,
            ]
        );

        // Attributes for T-Shirt
        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $tshirt->id, 'code' => 'size'],
            [
                'name' => 'Size',
                'type' => AttributeType::Select,
                'is_variant' => true,
                'is_required' => true,
                'sort_order' => 1,
                'options' => [
                    ['label' => 'S', 'value' => 's', 'is_active' => true],
                    ['label' => 'M', 'value' => 'm', 'is_active' => true],
                    ['label' => 'L', 'value' => 'l', 'is_active' => true],
                    ['label' => 'XL', 'value' => 'xl', 'is_active' => true],
                    ['label' => 'XXL', 'value' => 'xxl', 'is_active' => true],
                ],
            ]
        );

        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $tshirt->id, 'code' => 'color'],
            [
                'name' => 'Color',
                'type' => AttributeType::Select,
                'is_variant' => true,
                'is_required' => true,
                'sort_order' => 2,
                'options' => [
                    ['label' => 'Black', 'value' => 'black', 'is_active' => true],
                    ['label' => 'White', 'value' => 'white', 'is_active' => true],
                    ['label' => 'Navy Blue', 'value' => 'navy-blue', 'is_active' => true],
                    ['label' => 'Red', 'value' => 'red', 'is_active' => true],
                ],
            ]
        );

        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $tshirt->id, 'code' => 'fabric'],
            [
                'name' => 'Fabric',
                'type' => AttributeType::Select,
                'is_variant' => false,
                'is_required' => true,
                'sort_order' => 3,
                'options' => [
                    ['label' => 'Cotton', 'value' => 'cotton', 'is_active' => true],
                    ['label' => 'Polyester', 'value' => 'polyester', 'is_active' => true],
                    ['label' => 'Cotton Blend', 'value' => 'cotton-blend', 'is_active' => true],
                ],
            ]
        );

        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $tshirt->id, 'code' => 'sleeve'],
            [
                'name' => 'Sleeve',
                'type' => AttributeType::Select,
                'is_variant' => false,
                'is_required' => false,
                'sort_order' => 4,
                'options' => [
                    ['label' => 'Half Sleeve', 'value' => 'half-sleeve', 'is_active' => true],
                    ['label' => 'Full Sleeve', 'value' => 'full-sleeve', 'is_active' => true],
                    ['label' => 'Sleeveless', 'value' => 'sleeveless', 'is_active' => true],
                ],
            ]
        );

        // 3. Tree 2: Electronics › Mobiles
        $electronics = Category::firstOrCreate(
            ['slug' => 'electronics'],
            ['name' => 'Electronics', 'sort_order' => 2, 'is_active' => true]
        );

        $mobiles = Category::firstOrCreate(
            ['slug' => 'mobiles', 'parent_id' => $electronics->id],
            ['name' => 'Mobiles', 'sort_order' => 1, 'is_active' => true]
        );

        $smartphone = Vertical::firstOrCreate(
            ['slug' => 'smartphone'],
            [
                'category_id' => $mobiles->id,
                'name' => 'Smartphone',
                'hsn_code' => '8517',
                'gst_rate' => 18.00,
                'is_active' => true,
            ]
        );

        // Attributes for Smartphone
        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $smartphone->id, 'code' => 'ram'],
            [
                'name' => 'RAM',
                'type' => AttributeType::Select,
                'unit' => 'GB',
                'is_variant' => true,
                'is_required' => true,
                'sort_order' => 1,
                'options' => [
                    ['label' => '4 GB', 'value' => '4gb', 'is_active' => true],
                    ['label' => '6 GB', 'value' => '6gb', 'is_active' => true],
                    ['label' => '8 GB', 'value' => '8gb', 'is_active' => true],
                    ['label' => '12 GB', 'value' => '12gb', 'is_active' => true],
                ],
            ]
        );

        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $smartphone->id, 'code' => 'storage'],
            [
                'name' => 'Storage',
                'type' => AttributeType::Select,
                'unit' => 'GB',
                'is_variant' => true,
                'is_required' => true,
                'sort_order' => 2,
                'options' => [
                    ['label' => '64 GB', 'value' => '64gb', 'is_active' => true],
                    ['label' => '128 GB', 'value' => '128gb', 'is_active' => true],
                    ['label' => '256 GB', 'value' => '256gb', 'is_active' => true],
                    ['label' => '512 GB', 'value' => '512gb', 'is_active' => true],
                ],
            ]
        );

        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $smartphone->id, 'code' => 'color'],
            [
                'name' => 'Color',
                'type' => AttributeType::Select,
                'is_variant' => true,
                'is_required' => true,
                'sort_order' => 3,
                'options' => [
                    ['label' => 'Midnight Black', 'value' => 'midnight-black', 'is_active' => true],
                    ['label' => 'Ocean Blue', 'value' => 'ocean-blue', 'is_active' => true],
                    ['label' => 'Starlight', 'value' => 'starlight', 'is_active' => true],
                ],
            ]
        );

        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $smartphone->id, 'code' => 'battery_capacity'],
            [
                'name' => 'Battery Capacity',
                'type' => AttributeType::Number,
                'unit' => 'mAh',
                'is_variant' => false,
                'is_required' => false,
                'sort_order' => 4,
            ]
        );

        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $smartphone->id, 'code' => 'screen_size'],
            [
                'name' => 'Screen Size',
                'type' => AttributeType::Number,
                'unit' => 'inches',
                'is_variant' => false,
                'is_required' => false,
                'sort_order' => 5,
            ]
        );

        // 4. Tree 3: Toys & Games
        $toys = Category::firstOrCreate(
            ['slug' => 'toys-games'],
            ['name' => 'Toys & Games', 'sort_order' => 3, 'is_active' => true]
        );

        // 4a. Games & Puzzles › Board Game
        $gamesPuzzles = Category::firstOrCreate(
            ['slug' => 'games-puzzles', 'parent_id' => $toys->id],
            ['name' => 'Games & Puzzles', 'sort_order' => 1, 'is_active' => true]
        );

        $boardGame = Vertical::firstOrCreate(
            ['slug' => 'board-game'],
            [
                'category_id' => $gamesPuzzles->id,
                'name' => 'Board Game',
                'hsn_code' => '9504',
                'gst_rate' => 12.00,
                'is_active' => true,
            ]
        );

        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $boardGame->id, 'code' => 'edition'],
            [
                'name' => 'Edition',
                'type' => AttributeType::Select,
                'is_variant' => true,
                'is_required' => true,
                'sort_order' => 1,
                'options' => [
                    ['label' => 'Standard', 'value' => 'standard', 'is_active' => true],
                    ['label' => 'Deluxe', 'value' => 'deluxe', 'is_active' => true],
                    ['label' => "Collector's", 'value' => 'collectors', 'is_active' => true],
                ],
            ]
        );

        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $boardGame->id, 'code' => 'age_group'],
            [
                'name' => 'Age Group',
                'type' => AttributeType::Select,
                'is_variant' => false,
                'is_required' => false,
                'sort_order' => 2,
                'options' => [
                    ['label' => '3+ Years', 'value' => '3-plus', 'is_active' => true],
                    ['label' => '7+ Years', 'value' => '7-plus', 'is_active' => true],
                    ['label' => '10+ Years', 'value' => '10-plus', 'is_active' => true],
                    ['label' => '14+ Years', 'value' => '14-plus', 'is_active' => true],
                ],
            ]
        );

        // 4b. Action Figures & Collectibles › Action Figure
        $actionCollectibles = Category::firstOrCreate(
            ['slug' => 'action-figures-collectibles', 'parent_id' => $toys->id],
            ['name' => 'Action Figures & Collectibles', 'sort_order' => 2, 'is_active' => true]
        );

        $actionFigure = Vertical::firstOrCreate(
            ['slug' => 'action-figure'],
            [
                'category_id' => $actionCollectibles->id,
                'name' => 'Action Figure',
                'hsn_code' => '9503',
                'gst_rate' => 18.00,
                'is_active' => true,
            ]
        );

        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $actionFigure->id, 'code' => 'character'],
            [
                'name' => 'Character',
                'type' => AttributeType::Select,
                'is_variant' => true,
                'is_required' => true,
                'sort_order' => 1,
                'options' => [
                    ['label' => 'Iron Man', 'value' => 'iron-man', 'is_active' => true],
                    ['label' => 'Spider-Man', 'value' => 'spider-man', 'is_active' => true],
                    ['label' => 'Batman', 'value' => 'batman', 'is_active' => true],
                    ['label' => 'Darth Vader', 'value' => 'darth-vader', 'is_active' => true],
                ],
            ]
        );

        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $actionFigure->id, 'code' => 'material'],
            [
                'name' => 'Material',
                'type' => AttributeType::Select,
                'is_variant' => false,
                'is_required' => false,
                'sort_order' => 2,
                'options' => [
                    ['label' => 'Plastic', 'value' => 'plastic', 'is_active' => true],
                    ['label' => 'Vinyl', 'value' => 'vinyl', 'is_active' => true],
                    ['label' => 'Die-cast', 'value' => 'die-cast', 'is_active' => true],
                ],
            ]
        );

        // 4c. Building & Construction › Building Blocks
        $buildingConstruction = Category::firstOrCreate(
            ['slug' => 'building-construction', 'parent_id' => $toys->id],
            ['name' => 'Building & Construction', 'sort_order' => 3, 'is_active' => true]
        );

        $buildingBlocks = Vertical::firstOrCreate(
            ['slug' => 'building-blocks'],
            [
                'category_id' => $buildingConstruction->id,
                'name' => 'Building Blocks',
                'hsn_code' => '9503',
                'gst_rate' => 18.00,
                'is_active' => true,
            ]
        );

        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $buildingBlocks->id, 'code' => 'theme'],
            [
                'name' => 'Theme',
                'type' => AttributeType::Select,
                'is_variant' => true,
                'is_required' => true,
                'sort_order' => 1,
                'options' => [
                    ['label' => 'City', 'value' => 'city', 'is_active' => true],
                    ['label' => 'Space', 'value' => 'space', 'is_active' => true],
                    ['label' => 'Castle', 'value' => 'castle', 'is_active' => true],
                    ['label' => 'Superheroes', 'value' => 'superheroes', 'is_active' => true],
                ],
            ]
        );

        VerticalAttribute::firstOrCreate(
            ['vertical_id' => $buildingBlocks->id, 'code' => 'piece_count'],
            [
                'name' => 'Piece Count',
                'type' => AttributeType::Number,
                'unit' => 'pcs',
                'is_variant' => false,
                'is_required' => false,
                'sort_order' => 2,
            ]
        );
    }
}
