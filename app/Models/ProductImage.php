<?php

namespace App\Models;

use App\Enums\ProductImageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $path
 * @property int $sort_order
 * @property bool $is_primary
 * @property ProductImageStatus $status
 * @property Product $product
 * @property ProductVariant|null $variant
 */
class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'product_variant_id',
        'path',
        'sort_order',
        'is_primary',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
            'status' => ProductImageStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function url(): ?string
    {
        if (! $this->path) {
            return null;
        }

        return asset('storage/'.ltrim($this->path, '/'));
    }
}
