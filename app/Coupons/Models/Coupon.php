<?php

namespace App\Coupons\Models;

use App\Coupons\Enums\CouponFundedBy;
use App\Coupons\Enums\CouponStatus;
use App\Coupons\Enums\DiscountType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property CouponStatus $status
 * @property DiscountType $discount_type
 * @property int $discount_value
 * @property int|null $max_discount
 * @property int $min_order_amount
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $expires_at
 * @property int|null $usage_limit_total
 * @property int|null $usage_limit_per_user
 * @property int $usage_count
 * @property bool $first_order_only
 * @property CouponFundedBy $funded_by
 * @property int|null $seller_id
 * @property-read Collection<int, CouponScope> $scopes
 * @property-read Collection<int, CouponRedemption> $redemptions
 */
class Coupon extends Model
{
    protected $table = 'coupons';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CouponStatus::class,
            'discount_type' => DiscountType::class,
            'discount_value' => 'integer',
            'max_discount' => 'integer',
            'min_order_amount' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'usage_limit_total' => 'integer',
            'usage_limit_per_user' => 'integer',
            'usage_count' => 'integer',
            'first_order_only' => 'boolean',
            'funded_by' => CouponFundedBy::class,
            'seller_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<CouponScope, $this>
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(CouponScope::class, 'coupon_id');
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class, 'coupon_id');
    }

    public function hasRedemptions(): bool
    {
        return $this->redemptions()->exists();
    }

    protected static function booted(): void
    {
        static::saving(function (Coupon $coupon) {
            if ($coupon->funded_by === CouponFundedBy::Seller) {
                throw new \DomainException('Seller-funded coupons are not supported in this phase.');
            }
        });
    }
}
