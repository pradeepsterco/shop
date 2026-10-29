<?php

namespace App\Orders\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Units of one order item covered by a refund, with the coupon share attributed to those units.
 *
 * @property int $id
 * @property string $customer_refund_id
 * @property int $order_item_id
 * @property int $units
 * @property int $gross_paise
 * @property int $coupon_share_paise
 * @property int $amount_paise
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CustomerRefund $refund
 */
class CustomerRefundItem extends Model
{
    protected $table = 'customer_refund_items';

    protected $fillable = [
        'customer_refund_id',
        'order_item_id',
        'units',
        'gross_paise',
        'coupon_share_paise',
        'amount_paise',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_item_id' => 'integer',
            'units' => 'integer',
            'gross_paise' => 'integer',
            'coupon_share_paise' => 'integer',
            'amount_paise' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CustomerRefund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(CustomerRefund::class, 'customer_refund_id');
    }
}
