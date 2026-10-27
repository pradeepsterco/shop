<?php

namespace App\Coupons\Models;

use App\Coupons\Enums\CouponFundedBy;
use App\Coupons\Enums\RedemptionStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $coupon_id
 * @property string $coupon_code
 * @property string $customer_reference
 * @property string $order_reference
 * @property string $idempotency_key
 * @property int $discount_amount
 * @property array<string|int, int> $line_allocations
 * @property CouponFundedBy $funded_by
 * @property RedemptionStatus $status
 * @property int $released_amount
 * @property CarbonInterface $redeemed_at
 * @property CarbonInterface|null $released_at
 * @property-read Coupon $coupon
 */
class CouponRedemption extends Model
{
    protected $table = 'coupon_redemptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'coupon_id' => 'integer',
            'discount_amount' => 'integer',
            'line_allocations' => 'array',
            'funded_by' => CouponFundedBy::class,
            'status' => RedemptionStatus::class,
            'released_amount' => 'integer',
            'redeemed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }
}
