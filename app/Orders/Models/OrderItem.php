<?php

namespace App\Orders\Models;

use App\Models\Listing;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $seller_order_id
 * @property int|null $listing_id
 * @property int|null $product_variant_id
 * @property string $title
 * @property string|null $variant_title
 * @property string $sku
 * @property string $upid
 * @property string|null $image_path
 * @property string $seller_name
 * @property int $unit_price
 * @property int $mrp
 * @property int $qty
 * @property int $line_total
 * @property string|null $hsn_code
 * @property float $gst_rate
 * @property int $gst_amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SellerOrder $sellerOrder
 * @property-read Listing|null $listing
 * @property-read ProductVariant|null $variant
 */
class OrderItem extends Model
{
    protected $table = 'order_items';

    protected $fillable = [
        'seller_order_id',
        'listing_id',
        'product_variant_id',
        'title',
        'variant_title',
        'sku',
        'upid',
        'image_path',
        'seller_name',
        'unit_price',
        'mrp',
        'qty',
        'line_total',
        'hsn_code',
        'gst_rate',
        'gst_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'mrp' => 'integer',
            'qty' => 'integer',
            'line_total' => 'integer',
            'gst_rate' => 'float',
            'gst_amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SellerOrder, $this>
     */
    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class, 'seller_order_id');
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
