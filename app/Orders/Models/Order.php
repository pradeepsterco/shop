<?php

namespace App\Orders\Models;

use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_number
 * @property int $user_id
 * @property string $checkout_intent_id
 * @property OrderStatus $status
 * @property array<string, mixed> $delivery_address
 * @property string $delivery_state
 * @property PaymentMethod $payment_method
 * @property PaymentStatus $payment_status
 * @property int $items_mrp_total
 * @property int $discount_total
 * @property int $delivery_fee
 * @property int $grand_total
 * @property Carbon $placed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, SellerOrder> $sellerOrders
 */
class Order extends Model
{
    use HasUlids;

    protected $table = 'orders';

    protected $fillable = [
        'id',
        'order_number',
        'user_id',
        'checkout_intent_id',
        'status',
        'delivery_address',
        'delivery_state',
        'payment_method',
        'payment_status',
        'items_mrp_total',
        'discount_total',
        'delivery_fee',
        'grand_total',
        'placed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'delivery_address' => 'array',
            'placed_at' => 'datetime',
            'items_mrp_total' => 'integer',
            'discount_total' => 'integer',
            'delivery_fee' => 'integer',
            'grand_total' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SellerOrder, $this>
     */
    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class, 'order_id');
    }
}
