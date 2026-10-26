<?php

namespace Database\Factories;

use App\Enums\BrandStatus;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    protected $model = Brand::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => BrandStatus::Approved,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => BrandStatus::Pending,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => BrandStatus::Rejected,
            'rejection_reason' => 'Trademark documentation insufficient.',
        ]);
    }
}
