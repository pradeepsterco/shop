<?php

namespace Database\Factories;

use App\Enums\VariantQcStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'variant_attributes' => ['color' => 'black'],
            'barcode' => fake()->ean13(),
            'weight' => 0.45,
            'dimensions' => ['length' => 15, 'width' => 10, 'height' => 5],
            'qc_status' => VariantQcStatus::Approved,
            'qc_notes' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'qc_status' => VariantQcStatus::Draft,
        ]);
    }

    public function qcPending(): static
    {
        return $this->state(fn () => [
            'qc_status' => VariantQcStatus::QcPending,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'qc_status' => VariantQcStatus::Approved,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'qc_status' => VariantQcStatus::Rejected,
            'qc_notes' => 'Variant attributes do not match barcode documentation.',
        ]);
    }
}
