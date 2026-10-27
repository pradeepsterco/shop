<?php

namespace App\Coupons\Models;

use App\Coupons\Enums\ScopeRuleType;
use App\Coupons\Enums\ScopeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $coupon_id
 * @property ScopeType $scope_type
 * @property int $scope_id
 * @property ScopeRuleType $rule_type
 * @property-read Coupon $coupon
 */
class CouponScope extends Model
{
    protected $table = 'coupon_scopes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'coupon_id' => 'integer',
            'scope_type' => ScopeType::class,
            'scope_id' => 'integer',
            'rule_type' => ScopeRuleType::class,
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
