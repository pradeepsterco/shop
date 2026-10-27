<?php

namespace App\Coupons\Contracts;

use App\Coupons\DTOs\CouponEvaluation;
use App\Coupons\DTOs\RedemptionReleaseResult;
use App\Coupons\DTOs\RedemptionResult;

interface CouponRedemptionInterface
{
    public function redeem(
        CouponEvaluation $evaluation,
        string $customerReference,
        string $orderReference,
        string $idempotencyKey
    ): RedemptionResult;

    /**
     * Release a full order or arbitrary paise amount from redemption.
     */
    public function release(
        string $orderReference,
        ?int $releaseAmount = null
    ): RedemptionReleaseResult;

    /**
     * Cumulative partial return release calculation:
     * released_after = floor(D * unitsReturnedSoFar / Q);
     * each release = released_after - alreadyReleased;
     * last unit releases remainder (when unitsReturnedSoFar == totalUnits).
     */
    public function releaseCumulativeItemUnits(
        string $orderReference,
        string|int $lineId,
        int $unitsReturnedNow,
        int $unitsReturnedPrior,
        int $totalUnits,
        int $lineAllocatedDiscount
    ): RedemptionReleaseResult;
}
