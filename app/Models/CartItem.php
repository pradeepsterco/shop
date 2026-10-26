<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $listing_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $seller_profile_id
 * @property string|null $seller_name
 * @property string $name
 * @property string|null $sku
 * @property string|null $image
 * @property int $qty
 * @property int $unit_price
 * @property int $mrp
 * @property int|null $prev_unit_price
 * @property string $availability
 * @property int|null $available_qty
 * @property CarbonInterface|null $synced_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Cart $cart
 * @property-read Listing $listing
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read SellerProfile $sellerProfile
 */
class CartItem extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'integer',
            'mrp' => 'integer',
            'prev_unit_price' => 'integer',
            'available_qty' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
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

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }
}
