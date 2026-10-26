<?php

namespace Database\Factories;

use App\Enums\ProductImageStatus;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    protected $model = ProductImage::class;

    public function definition(): array
    {
        $path = 'products/placeholder.svg';

        if (! Storage::disk('public')->exists($path)) {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400"><rect width="400" height="400" fill="#f3f4f6"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#9ca3af" font-family="sans-serif" font-size="20">Khilauna Product</text></svg>';
            Storage::disk('public')->put($path, $svg);
        }

        return [
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'path' => $path,
            'sort_order' => 1,
            'is_primary' => true,
            'status' => ProductImageStatus::Live,
        ];
    }

    public function pendingAdd(): static
    {
        return $this->state(fn () => [
            'status' => ProductImageStatus::PendingAdd,
        ]);
    }

    public function pendingRemove(): static
    {
        return $this->state(fn () => [
            'status' => ProductImageStatus::PendingRemove,
        ]);
    }
}
