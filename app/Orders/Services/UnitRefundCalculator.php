<?php

namespace App\Orders\Services;

use App\Orders\DTOs\UnitRefundAmount;
use InvalidArgumentException;

/**
 * What the customer actually paid for k units of a line, after its coupon allocation.
 *
 * Uses the same cumulative rule as CouponRedemptionInterface::releaseCumulativeItemUnits, so the refund
 * and the coupon release always agree and a unit is never split:
 *
 *   cum(n)  = (n == Q) ? D : floor(D * n / Q)
 *   coupon  = cum(p + k) - cum(p)
 *   refund  = P * k - coupon
 *
 * Refunding every unit returns exactly line_total - D.
 */
final class UnitRefundCalculator
{
    public static function calculate(int $unitPricePaise, int $lineQty, int $lineCouponPaise, int $unitsPrior, int $units): UnitRefundAmount
    {
        if ($units <= 0 || $unitsPrior < 0 || $unitsPrior + $units > $lineQty) {
            throw new InvalidArgumentException("Cannot refund {$units} unit(s) after {$unitsPrior} of {$lineQty}.");
        }

        $gross = $unitPricePaise * $units;
        $coupon = self::cumulativeCoupon($lineCouponPaise, $lineQty, $unitsPrior + $units)
            - self::cumulativeCoupon($lineCouponPaise, $lineQty, $unitsPrior);

        return new UnitRefundAmount(
            grossPaise: $gross,
            couponSharePaise: $coupon,
            amountPaise: max(0, $gross - $coupon),
        );
    }

    public static function cumulativeCoupon(int $lineCouponPaise, int $lineQty, int $units): int
    {
        if ($units <= 0 || $lineCouponPaise <= 0) {
            return 0;
        }

        return $units >= $lineQty
            ? $lineCouponPaise
            : intdiv($lineCouponPaise * $units, $lineQty);
    }
}
