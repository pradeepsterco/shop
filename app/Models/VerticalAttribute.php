<?php

namespace App\Models;

use App\Enums\AttributeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $vertical_id
 * @property string $name
 * @property string $code
 * @property AttributeType $type
 * @property array<int, array{label: string, value: string, is_active: bool, is_retired?: bool}>|null $options
 * @property string|null $unit
 * @property bool $is_required
 * @property bool $is_variant
 * @property int $sort_order
 * @property Vertical $vertical
 */
class VerticalAttribute extends Model
{
    protected $fillable = [
        'vertical_id',
        'name',
        'code',
        'type',
        'options',
        'unit',
        'is_required',
        'is_variant',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_variant' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VerticalAttribute $attribute) {
            if (blank($attribute->code)) {
                $attribute->code = Str::slug($attribute->name, '_');
            }
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
     * Check if this vertical attribute is actively referenced by any product or variant.
     */
    public function isUsedInProducts(): bool
    {
        $code = $this->code;

        // Check non-variant usage in product specs JSON
        $usedInFamily = Product::where('vertical_id', $this->vertical_id)
            ->whereNotNull("specs->{$code}")
            ->exists();

        if ($usedInFamily) {
            return true;
        }

        // Check variant usage in product variants variant_attributes JSON
        return ProductVariant::whereHas('product', fn ($q) => $q->where('vertical_id', $this->vertical_id))
            ->whereNotNull("variant_attributes->{$code}")
            ->exists();
    }

    /**
     * Check if a specific option value is currently selected in any product or variant.
     */
    public function isOptionUsed(string $value): bool
    {
        $code = $this->code;

        $usedInFamily = Product::where('vertical_id', $this->vertical_id)
            ->where("specs->{$code}", $value)
            ->exists();

        if ($usedInFamily) {
            return true;
        }

        return ProductVariant::whereHas('product', fn ($q) => $q->where('vertical_id', $this->vertical_id))
            ->where("variant_attributes->{$code}", $value)
            ->exists();
    }

    /**
     * Return only active options for seller selection.
     *
     * @return array<int, array{label: string, value: string, is_active: bool, is_retired?: bool}>
     */
    public function activeOptions(): array
    {
        if (! is_array($this->options)) {
            return [];
        }

        return array_values(array_filter($this->options, fn ($opt) => ! empty($opt['is_active']) && empty($opt['is_retired'])));
    }
}
