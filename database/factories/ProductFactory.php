<?php

namespace Database\Factories;

use App\Enums\BrandStatus;
use App\Enums\NetQuantityUnit;
use App\Enums\ProductQcStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $title = sprintf('%s %s %s', fake()->word(), fake()->word(), fake()->word());

        return [
            'vertical_id' => Vertical::inRandomOrder()->value('id') ?? Vertical::firstOrCreate(
                ['slug' => 'sample-vertical'],
                [
                    'name' => 'Sample Vertical',
                    'hsn_code' => '9503',
                    'gst_rate' => 12.00,
                    'is_active' => true,
                    'category_id' => Category::firstOrCreate(['slug' => 'sample-category'], ['name' => 'Sample Category', 'is_active' => true])->id,
                ]
            )->id,
            'brand_id' => Brand::where('status', BrandStatus::Approved)->inRandomOrder()->value('id') ?? Brand::factory()->create()->id,
            'title' => ucfirst($title),
            'slug' => Str::slug($title),
            'description' => fake()->paragraph(),
            'highlights' => [fake()->sentence(), fake()->sentence()],
            'specs' => [],
            'generic_name' => 'Toy',
            'net_quantity' => 1,
            'net_quantity_unit' => NetQuantityUnit::Count,
            'created_by' => null,
            'qc_status' => ProductQcStatus::Approved,
            'qc_notes' => null,
            'reviewed_by_admin_id' => null,
            'reviewed_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'qc_status' => ProductQcStatus::Draft,
            'reviewed_at' => null,
            'reviewed_by_admin_id' => null,
        ]);
    }

    public function qcPending(): static
    {
        return $this->state(fn () => [
            'qc_status' => ProductQcStatus::QcPending,
            'reviewed_at' => null,
            'reviewed_by_admin_id' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'qc_status' => ProductQcStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'qc_status' => ProductQcStatus::Rejected,
            'qc_notes' => 'Product images or details do not meet catalog quality guidelines.',
            'reviewed_at' => now(),
        ]);
    }
}
