<?php

namespace App\Coupons\Services;

use App\Coupons\Contracts\CouponRedemptionInterface;
use App\Coupons\DTOs\CouponEvaluation;
use App\Coupons\DTOs\RedemptionReleaseResult;
use App\Coupons\DTOs\RedemptionResult;
use App\Coupons\Enums\CouponFundedBy;
use App\Coupons\Enums\CouponStatus;
use App\Coupons\Enums\RedemptionStatus;
use App\Coupons\Models\Coupon;
use App\Coupons\Models\CouponRedemption;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class CouponRedemptionService implements CouponRedemptionInterface
{
    public function redeem(
        CouponEvaluation $evaluation,
        string $customerReference,
        string $orderReference,
        string $idempotencyKey
    ): RedemptionResult {
        if (! $evaluation->isValid || $evaluation->couponId === null) {
            return RedemptionResult::failed($evaluation->message ?? 'Coupon evaluation is invalid.');
        }

        // Idempotency: return existing redemption if already processed with this key
        /** @var CouponRedemption|null $existing */
        $existing = CouponRedemption::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return new RedemptionResult(
                success: true,
                redemptionId: $existing->id,
                discountAmount: (int) $existing->discount_amount,
                lineAllocations: $existing->line_allocations,
            );
        }

        // Pessimistic lock on coupon row
        // NOTE: In SQLite during testing, lockForUpdate is a no-op; in production MySQL/PostgreSQL,
        // this acquires an exclusive row lock guaranteeing atomic limit enforcement.
        /** @var Coupon|null $coupon */
        $coupon = Coupon::where('id', $evaluation->couponId)->lockForUpdate()->first();

        if (! $coupon) {
            throw new DomainException('Coupon does not exist.');
        }

        if ($coupon->status !== CouponStatus::Active) {
            throw new DomainException('Coupon is no longer active.');
        }

        if ($coupon->funded_by === CouponFundedBy::Seller) {
            throw new DomainException('Seller-funded coupons are not supported in this phase.');
        }

        $now = CarbonImmutable::now();
        if ($coupon->starts_at && $now->lt($coupon->starts_at)) {
            throw new DomainException('Coupon is not yet active.');
        }
        if ($coupon->expires_at && $now->gt($coupon->expires_at)) {
            throw new DomainException('Coupon has expired.');
        }

        // Active statuses that count against usage limits
        $activeStatuses = [RedemptionStatus::Redeemed->value, RedemptionStatus::PartiallyReleased->value];

        if ($coupon->usage_limit_per_user !== null && $customerReference !== '') {
            $userUsage = CouponRedemption::query()
                ->where('coupon_id', $coupon->id)
                ->where('customer_reference', $customerReference)
                ->whereIn('status', $activeStatuses)
                ->count();

            if ($userUsage >= $coupon->usage_limit_per_user) {
                throw new DomainException('You have reached the maximum usage limit for this coupon.');
            }
        }

        if ($coupon->usage_limit_total !== null) {
            $globalUsage = CouponRedemption::query()
                ->where('coupon_id', $coupon->id)
                ->whereIn('status', $activeStatuses)
                ->count();

            if ($globalUsage >= $coupon->usage_limit_total) {
                throw new DomainException('Coupon usage limit has been reached.');
            }
        }

        /** @var CouponRedemption $redemption */
        $redemption = CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
            'customer_reference' => $customerReference,
            'order_reference' => $orderReference,
            'idempotency_key' => $idempotencyKey,
            'discount_amount' => $evaluation->totalDiscount,
            'line_allocations' => $evaluation->lineAllocations,
            'funded_by' => $coupon->funded_by ?? CouponFundedBy::Platform,
            'status' => RedemptionStatus::Redeemed,
            'released_amount' => 0,
            'redeemed_at' => $now,
        ]);

        $coupon->increment('usage_count');

        return new RedemptionResult(
            success: true,
            redemptionId: $redemption->id,
            discountAmount: (int) $redemption->discount_amount,
            lineAllocations: $redemption->line_allocations,
        );
    }

    public function release(
        string $orderReference,
        ?int $releaseAmount = null
    ): RedemptionReleaseResult {
        return DB::transaction(function () use ($orderReference, $releaseAmount) {
            /** @var CouponRedemption|null $redemption */
            $redemption = CouponRedemption::where('order_reference', $orderReference)
                ->lockForUpdate()
                ->first();

            if (! $redemption) {
                return RedemptionReleaseResult::failed("No redemption found for order {$orderReference}.");
            }

            if ($redemption->status === RedemptionStatus::Released) {
                return new RedemptionReleaseResult(
                    success: true,
                    releasedAmount: 0,
                    cumulativeReleasedAmount: (int) $redemption->released_amount,
                    status: $redemption->status->value,
                );
            }

            $currentReleased = (int) $redemption->released_amount;
            $totalDiscount = (int) $redemption->discount_amount;
            $unreleasedAmount = max(0, $totalDiscount - $currentReleased);

            $amountToRelease = ($releaseAmount === null)
                ? $unreleasedAmount
                : min($releaseAmount, $unreleasedAmount);

            $newCumulativeReleased = $currentReleased + $amountToRelease;
            $newStatus = ($newCumulativeReleased >= $totalDiscount)
                ? RedemptionStatus::Released
                : RedemptionStatus::PartiallyReleased;

            $redemption->update([
                'released_amount' => $newCumulativeReleased,
                'status' => $newStatus,
                'released_at' => CarbonImmutable::now(),
            ]);

            // Decrement usage_count only when fully released
            if ($newStatus === RedemptionStatus::Released) {
                $redemption->coupon()->decrement('usage_count');
            }

            return new RedemptionReleaseResult(
                success: true,
                releasedAmount: $amountToRelease,
                cumulativeReleasedAmount: $newCumulativeReleased,
                status: $newStatus->value,
            );
        });
    }

    public function releaseCumulativeItemUnits(
        string $orderReference,
        string|int $lineId,
        int $unitsReturnedNow,
        int $unitsReturnedPrior,
        int $totalUnits,
        int $lineAllocatedDiscount
    ): RedemptionReleaseResult {
        if ($totalUnits <= 0 || $unitsReturnedNow <= 0 || $lineAllocatedDiscount <= 0) {
            return new RedemptionReleaseResult(
                success: true,
                releasedAmount: 0,
                cumulativeReleasedAmount: 0,
                status: 'unchanged',
            );
        }

        $unitsSoFar = min($totalUnits, $unitsReturnedPrior + $unitsReturnedNow);

        // Cumulative formula:
        // released_after = (unitsSoFar == totalUnits) ? lineAllocatedDiscount : floor(D * unitsSoFar / Q)
        $releasedAfter = ($unitsSoFar >= $totalUnits)
            ? $lineAllocatedDiscount
            : (int) floor(($lineAllocatedDiscount * $unitsSoFar) / $totalUnits);

        // alreadyReleased = (unitsReturnedPrior == 0) ? 0 : floor(D * unitsReturnedPrior / Q)
        $alreadyReleased = ($unitsReturnedPrior <= 0)
            ? 0
            : (int) floor(($lineAllocatedDiscount * $unitsReturnedPrior) / $totalUnits);

        $releaseNow = max(0, $releasedAfter - $alreadyReleased);

        return $this->release($orderReference, $releaseNow);
    }
}
