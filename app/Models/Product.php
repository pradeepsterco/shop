<?php

namespace App\Models;

use App\Enums\BrandStatus;
use App\Enums\NetQuantityUnit;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Search\Contracts\SearchIndexerInterface;
use App\Services\ProductBuyBoxService;
use Database\Factories\ProductFactory;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $vertical_id
 * @property int $brand_id
 * @property string $title
 * @property string $slug
 * @property string $description
 * @property array<int, string>|null $highlights
 * @property array<string, mixed>|null $specs
 * @property string|null $generic_name
 * @property string|null $net_quantity
 * @property NetQuantityUnit|null $net_quantity_unit
 * @property int|null $created_by
 * @property ProductQcStatus $qc_status
 * @property string|null $qc_notes
 * @property int|null $reviewed_by_admin_id
 * @property Carbon|null $reviewed_at
 * @property array<string, mixed>|null $pending_changes
 * @property int|null $buy_box_listing_id
 * @property int|null $min_price
 * @property int|null $buy_box_mrp
 * @property int|null $discount_percent
 * @property bool $in_stock
 * @property Vertical|null $vertical
 * @property Brand|null $brand
 * @property SellerProfile|null $creator
 * @property Admin|null $reviewer
 * @property Listing|null $buyBoxListing
 * @property Collection<int, ProductVariant> $variants
 * @property Collection<int, ProductImage> $images
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Catalog content that a creator's edit to an approved product parks in pending_changes until QC approves it.
     */
    public const PENDING_CHANGE_FIELDS = ['title', 'description', 'highlights', 'specs', 'brand_id', 'generic_name', 'net_quantity', 'net_quantity_unit'];

    protected $fillable = [
        'vertical_id',
        'brand_id',
        'title',
        'slug',
        'description',
        'highlights',
        'specs',
        'generic_name',
        'net_quantity',
        'net_quantity_unit',
        'created_by',
        'qc_status',
        'qc_notes',
        'reviewed_by_admin_id',
        'reviewed_at',
        'pending_changes',
        'needs_update',
        'needs_update_reason',
        'buy_box_listing_id',
        'min_price',
        'buy_box_mrp',
        'discount_percent',
        'in_stock',
    ];

    protected function casts(): array
    {
        return [
            'highlights' => 'array',
            'specs' => 'array',
            'net_quantity' => 'decimal:3',
            'net_quantity_unit' => NetQuantityUnit::class,
            'pending_changes' => 'array',
            'qc_status' => ProductQcStatus::class,
            'reviewed_at' => 'datetime',
            'needs_update' => 'boolean',
            'needs_update_reason' => 'array',
            'in_stock' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (blank($product->slug)) {
                $brandName = $product->brand ? $product->brand->name : 'brand';
                $product->slug = Str::slug("{$brandName} {$product->title}");
            }
        });

        static::saved(function (Product $product) {
            if ($product->wasChanged('qc_status')) {
                // Also reindexes search.
                app(ProductBuyBoxService::class)->refresh($product);
            } elseif ($product->wasChanged(['title', 'slug', 'highlights', 'specs', 'brand_id', 'vertical_id'])) {
                app(SearchIndexerInterface::class)->queue($product->id);
            }
        });

        static::deleted(function (Product $product) {
            app(SearchIndexerInterface::class)->queue($product->id);
        });
    }

    /**
     * @return BelongsTo<Vertical, $this>
     */
    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'created_by');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function buyBoxListing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'buy_box_listing_id');
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /**
     * @return HasManyThrough<Listing, ProductVariant, $this>
     */
    public function listings(): HasManyThrough
    {
        return $this->hasManyThrough(Listing::class, ProductVariant::class);
    }

    /**
     * Inspect product and its variants/listings for integrity issues.
     * Pure function: returns structured issues array without modifying database.
     *
     * @return array<int, array{variant_id: int|null, code: string, message: string}>
     */
    public function variantIssues(): array
    {
        $issues = [];
        $variants = $this->relationLoaded('variants')
            ? $this->variants
            : $this->variants()->with(['listings'])->get();

        if ($variants->isEmpty()) {
            $issues[] = [
                'variant_id' => null,
                'code' => 'no_variants',
                'message' => 'Product has no variants configured.',
            ];

            return $issues;
        }

        $vertical = $this->relationLoaded('vertical')
            ? $this->vertical
            : $this->vertical()->with('attributes')->first();

        $attributesMap = [];
        if ($vertical) {
            $attrs = $vertical->relationLoaded('attributes')
                ? $vertical->getRelation('attributes')
                : $vertical->attributes()->get();

            foreach ($attrs as $attr) {
                $attributesMap[$attr->code] = $attr;
            }
        }

        $activeValidCount = 0;
        $seenKeys = [];

        foreach ($variants as $variant) {
            $vKey = $variant->variant_key;

            // Check duplicate keys
            if (isset($seenKeys[$vKey])) {
                $issues[] = [
                    'variant_id' => $variant->id,
                    'code' => 'duplicate_signature',
                    'message' => "Duplicate variant signature '{$vKey}' detected.",
                ];
            }
            $seenKeys[$vKey] = true;

            // Check listings for this variant
            $listings = $variant->relationLoaded('listings')
                ? $variant->listings
                : $variant->listings()->get();

            if ($listings->isEmpty()) {
                $issues[] = [
                    'variant_id' => $variant->id,
                    'code' => 'missing_listing',
                    'message' => "Variant {$vKey} has no pricing or stock offers.",
                ];

                continue;
            }

            $variantHasValidActiveListing = false;

            foreach ($listings as $listing) {
                if ($listing->trashed()) {
                    continue;
                }

                $listingInvalid = false;

                if (blank($listing->sku)) {
                    $issues[] = [
                        'variant_id' => $variant->id,
                        'code' => 'missing_sku',
                        'message' => "Variant {$vKey} has a listing with a blank SKU.",
                    ];
                    $listingInvalid = true;
                }

                if ($listing->mrp <= 0) {
                    $issues[] = [
                        'variant_id' => $variant->id,
                        'code' => 'invalid_mrp',
                        'message' => "Variant {$vKey} MRP must be greater than 0.",
                    ];
                    $listingInvalid = true;
                }

                if ($listing->selling_price <= 0) {
                    $issues[] = [
                        'variant_id' => $variant->id,
                        'code' => 'missing_price',
                        'message' => "Variant {$vKey} selling price must be greater than 0.",
                    ];
                    $listingInvalid = true;
                } elseif ($listing->selling_price > $listing->mrp) {
                    $issues[] = [
                        'variant_id' => $variant->id,
                        'code' => 'price_exceeds_mrp',
                        'message' => "Variant {$vKey} selling price cannot exceed MRP.",
                    ];
                    $listingInvalid = true;
                }

                if ($listing->stock < 0) {
                    $issues[] = [
                        'variant_id' => $variant->id,
                        'code' => 'invalid_stock',
                        'message' => "Variant {$vKey} stock cannot be negative.",
                    ];
                    $listingInvalid = true;
                }

                if (! $listingInvalid && $listing->status->value === 'active') {
                    $variantHasValidActiveListing = true;
                }
            }

            if ($variantHasValidActiveListing) {
                $activeValidCount++;
            }
        }

        // If product has no active valid variants at all
        if ($activeValidCount === 0 && $this->qc_status === ProductQcStatus::Approved) {
            $issues[] = [
                'variant_id' => null,
                'code' => 'no_active_variants',
                'message' => 'Product has no valid active variants listed.',
            ];
        }

        return $issues;
    }

    /**
     * Deactivates only listings that are truly unsellable (missing/zero price or price > MRP or missing SKU).
     */
    public function deactivateUnsellableListings(): int
    {
        $deactivated = 0;

        foreach ($this->variants()->with('listings')->get() as $variant) {
            foreach ($variant->listings as $listing) {
                if ($listing->trashed() || $listing->status->value !== 'active') {
                    continue;
                }

                if ($listing->selling_price <= 0 || $listing->mrp <= 0 || $listing->selling_price > $listing->mrp || blank($listing->sku)) {
                    $listing->deactivate();
                    $deactivated++;
                }
            }
        }

        return $deactivated;
    }

    /**
     * Checks issues and updates needs_update flag and reason.
     */
    public function flagNeedsUpdate(): bool
    {
        $issues = $this->variantIssues();

        if (! empty($issues)) {
            $this->update([
                'needs_update' => true,
                'needs_update_reason' => $issues,
            ]);
            $this->deactivateUnsellableListings();

            return true;
        }

        if ($this->needs_update) {
            $this->update([
                'needs_update' => false,
                'needs_update_reason' => null,
            ]);
        }

        return false;
    }

    /**
     * Return active options plus options already present on this product's variants for the given attribute.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableOptionsForAttribute(VerticalAttribute $attr): array
    {
        $options = is_array($attr->options) ? $attr->options : [];

        $existingValues = [];
        $variants = $this->relationLoaded('variants') ? $this->variants : $this->variants()->get();
        foreach ($variants as $variant) {
            if (! $variant->trashed() && isset($variant->variant_attributes[$attr->code])) {
                $existingValues[] = (string) $variant->variant_attributes[$attr->code];
            }
        }

        $available = [];
        foreach ($options as $opt) {
            $val = (string) $opt['value'];
            $isRetired = ! empty($opt['is_retired']);
            $isActive = ! empty($opt['is_active']) && ! $isRetired;
            $isExisting = in_array($val, $existingValues, true);

            if ($isActive || $isExisting) {
                $opt['is_existing_on_product'] = $isExisting;
                $opt['is_retired'] = $isRetired;
                $available[] = $opt;
            }
        }

        return $available;
    }

    public function canonicalSlug(): string
    {
        $brandName = $this->brand ? $this->brand->name : 'brand';

        return Str::slug("{$brandName} {$this->title}");
    }

    /**
     * Package declarations still missing, counting pending changes (labels for messages).
     *
     * @return array<int, string>
     */
    public function missingDeclarations(): array
    {
        $pending = (array) $this->pending_changes;
        $genericName = array_key_exists('generic_name', $pending) ? $pending['generic_name'] : $this->generic_name;
        $quantity = array_key_exists('net_quantity', $pending) ? $pending['net_quantity'] : $this->net_quantity;
        $unit = array_key_exists('net_quantity_unit', $pending) ? $pending['net_quantity_unit'] : $this->net_quantity_unit?->value;

        $missing = [];
        if (blank($genericName)) {
            $missing[] = 'generic name';
        }
        if (! is_numeric($quantity) || (float) $quantity <= 0 || NetQuantityUnit::tryFrom((string) $unit) === null) {
            $missing[] = 'net quantity';
        }

        return $missing;
    }

    /**
     * e.g. "1 N", "500 g".
     */
    public function netQuantityLabel(): ?string
    {
        return NetQuantityUnit::format($this->net_quantity, $this->net_quantity_unit);
    }

    public function submitForQc(): void
    {
        $this->update([
            'qc_status' => ProductQcStatus::QcPending,
        ]);
    }

    public function approve(Admin $admin, ?string $notes = null): void
    {
        if ($this->brand && $this->brand->status !== BrandStatus::Approved) {
            throw new DomainException('Cannot approve product while associated brand is pending review.');
        }

        $dataToUpdate = [
            'qc_status' => ProductQcStatus::Approved,
            'qc_notes' => $notes,
            'reviewed_by_admin_id' => $admin->id,
            'reviewed_at' => now(),
        ];

        // Apply pending changes if present
        if (! empty($this->pending_changes)) {
            foreach ($this->pending_changes as $key => $val) {
                if (in_array($key, self::PENDING_CHANGE_FIELDS, true)) {
                    $dataToUpdate[$key] = $val;
                }
            }
            $dataToUpdate['pending_changes'] = null;
        }

        $this->update($dataToUpdate);

        // Regenerate canonical slug
        $this->refresh();
        $this->update(['slug' => $this->canonicalSlug()]);

        // Process image changes
        foreach ($this->images as $img) {
            if ($img->status === ProductImageStatus::PendingAdd) {
                $img->update(['status' => ProductImageStatus::Live]);
            } elseif ($img->status === ProductImageStatus::PendingRemove) {
                if ($img->path !== '' && Storage::disk('public')->exists($img->path)) {
                    Storage::disk('public')->delete($img->path);
                }
                $img->delete();
            }
        }
    }

    public function reject(Admin $admin, string $notes): void
    {
        $this->update([
            'qc_status' => ProductQcStatus::Rejected,
            'qc_notes' => $notes,
            'reviewed_by_admin_id' => $admin->id,
            'reviewed_at' => now(),
        ]);

        // Clean up pending add images and revert pending remove images
        foreach ($this->images as $img) {
            if ($img->status === ProductImageStatus::PendingAdd) {
                if ($img->path !== '' && Storage::disk('public')->exists($img->path)) {
                    Storage::disk('public')->delete($img->path);
                }
                $img->delete();
            } elseif ($img->status === ProductImageStatus::PendingRemove) {
                $img->update(['status' => ProductImageStatus::Live]);
            }
        }
    }
}
