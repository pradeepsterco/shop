<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductOffer extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'mrp' => 'decimal:2',
        'price' => 'decimal:2',
        'stock' => 'integer',
        'status' => 'boolean',
    ];

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
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_id');
    }

    /**
     * Offers a customer can actually buy right now.
     *
     * @param  Builder<$this>  $q
     * @return Builder<$this>
     */
    public function scopeBuyable(Builder $q): Builder
    {
        return $q->where('status', true)->where('stock', '>', 0);
    }
}
