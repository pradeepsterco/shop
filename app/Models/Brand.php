<?php

namespace App\Models;

use App\Enums\BrandStatus;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property BrandStatus $status
 * @property int|null $requested_by
 * @property string|null $rejection_reason
 * @property SellerProfile|null $requester
 * @property Collection<int, Product> $products
 */
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'logo_path',
        'status',
        'requested_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => BrandStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Brand $brand) {
            if (blank($brand->slug)) {
                $brand->slug = static::generateUniqueSlug($brand->name);
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
     * @return BelongsTo<SellerProfile, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'requested_by');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function approve(): void
    {
        $this->update([
            'status' => BrandStatus::Approved,
            'rejection_reason' => null,
        ]);
    }

    public function reject(string $reason): void
    {
        $this->update([
            'status' => BrandStatus::Rejected,
            'rejection_reason' => $reason,
        ]);

        // When a brand is rejected, associated products in pending QC go back to rejected
        $this->products()
            ->where('qc_status', 'qc_pending')
            ->update([
                'qc_status' => 'rejected',
                'qc_notes' => 'Associated brand request was rejected. Please select an approved brand.',
            ]);
    }

    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }
}
