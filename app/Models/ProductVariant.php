<?php

namespace App\Models;

use App\Enums\VariantQcStatus;
use App\Services\ProductBuyBoxService;
use App\Services\UpidGenerator;
use App\Services\VariantKeyNormalizer;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @property int $id
 * @property int $product_id
 * @property string $upid
 * @property string $variant_key
 * @property array<string, mixed> $variant_attributes
 * @property array<string, array{value: string, label: string}>|null $attribute_snapshot
 * @property string|null $barcode
 * @property float|null $weight
 * @property array<string, mixed>|null $dimensions
 * @property VariantQcStatus $qc_status
 * @property string|null $qc_notes
 * @property Product $product
 * @property Collection<int, ProductImage> $images
 * @property Collection<int, Listing> $listings
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'upid',
        'variant_key',
        'variant_attributes',
        'attribute_snapshot',
        'barcode',
        'weight',
        'dimensions',
        'qc_status',
        'qc_notes',
    ];

    protected function casts(): array
    {
        return [
            'variant_attributes' => 'array',
            'attribute_snapshot' => 'array',
            'dimensions' => 'array',
            'weight' => 'float',
            'qc_status' => VariantQcStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant) {
            if (blank($variant->upid)) {
                $variant->upid = UpidGenerator::generate();
            }

            if (blank($variant->variant_attributes)) {
                $variant->variant_attributes = [];
            }

            if (blank($variant->variant_key)) {
                $variant->variant_key = ! empty($variant->variant_attributes)
                    ? VariantKeyNormalizer::normalize($variant->variant_attributes)
                    : 'default';
            }
        });

        static::saved(function (ProductVariant $variant) {
            if ($variant->product_id && $variant->wasChanged('qc_status')) {
                app(ProductBuyBoxService::class)->refresh($variant->product_id);
            }
        });

        static::deleted(function (ProductVariant $variant) {
            if ($variant->product_id) {
                app(ProductBuyBoxService::class)->refresh($variant->product_id);
            }
        });

        static::restored(function (ProductVariant $variant) {
            if ($variant->product_id) {
                app(ProductBuyBoxService::class)->refresh($variant->product_id);
            }
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_variant_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'product_variant_id');
    }

    public function submitForQc(): void
    {
        $this->update(['qc_status' => VariantQcStatus::QcPending]);
    }

    public function approve(?string $notes = null): void
    {
        $this->update([
            'qc_status' => VariantQcStatus::Approved,
            'qc_notes' => $notes,
        ]);
    }

    public function reject(string $notes): void
    {
        $this->update([
            'qc_status' => VariantQcStatus::Rejected,
            'qc_notes' => $notes,
        ]);
    }

    /**
     * Check if variant has order/cart/wishlist history.
     */
    public function hasHistory(): bool
    {
        if (Schema::hasTable('order_items') && DB::table('order_items')->where('product_variant_id', $this->id)->exists()) {
            return true;
        }

        if (Schema::hasTable('cart_items') && DB::table('cart_items')->where('product_variant_id', $this->id)->exists()) {
            return true;
        }

        if (Schema::hasTable('wishlist_items') && DB::table('wishlist_items')->where('product_variant_id', $this->id)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Look up current option label by slug with fallback to snapshot label.
     */
    public function optionDisplayLabel(string $attrCode): string
    {
        $val = $this->variant_attributes[$attrCode] ?? ($this->attribute_snapshot[$attrCode]['value'] ?? null);
        if ($val === null || $val === '') {
            return '';
        }

        // Try lookup in current vertical attribute options
        $vertical = $this->product->vertical;
        if ($vertical) {
            $attrs = $vertical->relationLoaded('attributes') ? $vertical->getRelation('attributes') : $vertical->attributes()->get();
            $attr = $attrs->firstWhere('code', $attrCode);
            if ($attr && is_array($attr->options)) {
                foreach ($attr->options as $opt) {
                    if (($opt['value'] ?? '') === (string) $val && ! empty($opt['label'])) {
                        return $opt['label'];
                    }
                }
            }
        }

        // Fall back to snapshot label
        return $this->attribute_snapshot[$attrCode]['label'] ?? (string) $val;
    }

    /**
     * Formats user-facing variant title from snapshot/current labels.
     */
    public function displayTitle(): string
    {
        if (empty($this->variant_attributes)) {
            return $this->upid;
        }

        $labels = [];
        foreach ($this->variant_attributes as $code => $val) {
            $labels[] = $this->optionDisplayLabel($code);
        }

        return implode(' / ', array_filter($labels)) ?: $this->variant_key;
    }
}
