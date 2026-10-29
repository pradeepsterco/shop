<?php

namespace App\Orders\Models;

use App\Models\SellerProfile;
use App\Orders\Enums\SellerOrderStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_id
 * @property int|null $seller_profile_id
 * @property string $seller_store_name
 * @property string|null $seller_gstin
 * @property string|null $seller_pickup_state
 * @property string|null $invoice_number
 * @property Carbon|null $invoice_date
 * @property SellerOrderStatus $status
 * @property int $subtotal
 * @property int $discount_total
 * @property int $grand_total
 * @property Carbon $placed_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $packed_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $rto_delivered_at
 * @property string|null $replacement_of_return_request_id
 * @property int|null $expected_cod_amount_paise COD to collect when only some items were delivered; null = full grand_total
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property string|null $cancelled_by_type
 * @property int|null $cancelled_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read SellerProfile|null $sellerProfile
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderStatusHistory> $statusHistories
 */
class SellerOrder extends Model
{
    use HasUlids;

    protected $table = 'seller_orders';

    protected $fillable = [
        'id',
        'order_id',
        'seller_profile_id',
        'seller_store_name',
        'seller_gstin',
        'seller_pickup_state',
        'status',
        'subtotal',
        'discount_total',
        'coupon_discount_total',
        'grand_total',
        'placed_at',
        'confirmed_at',
        'packed_at',
        'shipped_at',
        'courier_name',
        'tracking_number',
        'delivered_at',
        'rto_delivered_at',
        'expected_cod_amount_paise',
        'replacement_of_return_request_id',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by_type',
        'cancelled_by_id',
        'invoice_number',
        'invoice_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SellerOrderStatus::class,
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'coupon_discount_total' => 'integer',
            'grand_total' => 'integer',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'packed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'rto_delivered_at' => 'datetime',
            'expected_cod_amount_paise' => 'integer',
            'cancelled_at' => 'datetime',
            'invoice_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'seller_order_id');
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'seller_order_id');
    }
}
