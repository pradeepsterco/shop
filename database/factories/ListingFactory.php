<?php

namespace Database\Factories;

use App\Enums\ListingStatus;
use App\Enums\SellerStatus;
use App\Models\Listing;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    public function definition(): array
    {
        $mrp = fake()->numberBetween(1500, 10000) * 100;
        $sellingPrice = (int) ($mrp * fake()->randomFloat(2, 0.6, 0.95));

        return [
            'seller_profile_id' => SellerProfile::where('status', SellerStatus::Approved)->inRandomOrder()->value('id') ?? SellerProfile::factory()->approved(),
            'product_variant_id' => ProductVariant::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-????')),
            'mrp' => $mrp,
            'selling_price' => $sellingPrice,
            'stock' => 20,
            'status' => ListingStatus::Active,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::Active,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::Inactive,
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => [
            'stock' => 0,
            'status' => ListingStatus::Active,
        ]);
    }
}
