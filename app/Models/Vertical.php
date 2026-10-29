<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $category_id
 * @property string $name
 * @property string $slug
 * @property string $hsn_code
 * @property float $gst_rate
 * @property bool $is_active
 * @property bool $is_returnable
 * @property int|null $return_window_days
 * @property Category|null $category
 * @property Collection<int, VerticalAttribute> $attributes
 * @property Collection<int, Product> $products
 */
class Vertical extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'hsn_code',
        'gst_rate',
        'is_active',
        'is_returnable',
        'return_window_days',
    ];

    protected $attributes = [
        'is_returnable' => true,
    ];

    protected function casts(): array
    {
        return [
            'gst_rate' => 'float',
            'is_active' => 'boolean',
            'is_returnable' => 'boolean',
            'return_window_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Vertical $vertical) {
            if (blank($vertical->slug)) {
                $vertical->slug = static::generateUniqueSlug($vertical->name);
            }
        });
    }

    public static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $counter = 1;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<VerticalAttribute, $this>
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(VerticalAttribute::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function breadcrumbString(): string
    {
        $catBreadcrumb = $this->category ? $this->category->breadcrumbString() : '';

        return $catBreadcrumb ? "{$catBreadcrumb} › {$this->name}" : $this->name;
    }
}
