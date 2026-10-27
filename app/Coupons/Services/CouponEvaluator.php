<?php

namespace App\Coupons\Services;

use App\Coupons\Contracts\CouponEvaluatorInterface;
use App\Coupons\Contracts\CustomerEligibilityInterface;
use App\Coupons\DTOs\CartContext;
use App\Coupons\DTOs\CartLineContext;
use App\Coupons\DTOs\CouponEvaluation;
use App\Coupons\Enums\CouponFundedBy;
use App\Coupons\Enums\CouponStatus;
use App\Coupons\Enums\DiscountType;
use App\Coupons\Enums\RedemptionStatus;
use App\Coupons\Enums\ScopeRuleType;
use App\Coupons\Enums\ScopeType;
use App\Coupons\Models\Coupon;
use App\Coupons\Models\CouponRedemption;
use Carbon\CarbonImmutable;

class CouponEvaluator implements CouponEvaluatorInterface
{
    /** Minimum payable amount after discount in paise (100 paise = ₹1.00) */
    public const MIN_PAYABLE_PAISE = 100;

    public function __construct(
        protected ?CustomerEligibilityInterface $customerEligibility = null,
    ) {}

    public function evaluate(CartContext $cart, string $code): CouponEvaluation
    {
        if (! config('coupons.enabled', false)) {
            return CouponEvaluation::invalid('FEATURE_DISABLED', 'Coupons are currently disabled.');
        }

        $normalizedCode = strtoupper(trim($code));

        if ($normalizedCode === '') {
            return CouponEvaluation::invalid('EMPTY_CODE', 'Please enter a coupon code.');
        }

        /** @var Coupon|null $coupon */
        $coupon = Coupon::with('scopes')->where('code', $normalizedCode)->first();

        if (! $coupon) {
            return CouponEvaluation::invalid('COUPON_NOT_FOUND', 'Coupon code is invalid.', $normalizedCode);
        }

        // 1. Status check
        if ($coupon->status !== CouponStatus::Active) {
            $msg = match ($coupon->status) {
                CouponStatus::Draft => 'Coupon is not active yet.',
                CouponStatus::Paused => 'Coupon is currently paused.',
                default => 'Coupon has expired.',
            };

            return CouponEvaluation::invalid('INACTIVE_COUPON', $msg, $coupon->code);
        }

        // 2. Date checks
        $now = $cart->now ? CarbonImmutable::instance($cart->now) : CarbonImmutable::now();

        if ($coupon->starts_at && $now->lt($coupon->starts_at)) {
            return CouponEvaluation::invalid('NOT_YET_STARTED', 'Coupon is not yet active.', $coupon->code);
        }

        if ($coupon->expires_at && $now->gt($coupon->expires_at)) {
            return CouponEvaluation::invalid('EXPIRED', 'Coupon has expired.', $coupon->code);
        }

        // 3. Supported funding check (Phase 1: Platform-funded only)
        if ($coupon->funded_by === CouponFundedBy::Seller) {
            return CouponEvaluation::invalid('SELLER_FUNDED_UNSUPPORTED', 'Seller-funded coupons are not supported in this phase.', $coupon->code);
        }

        // 4. Usage limit checks (redeemed and partially_released count towards limits)
        $activeStatuses = [RedemptionStatus::Redeemed->value, RedemptionStatus::PartiallyReleased->value];

        if ($coupon->usage_limit_per_user !== null && $cart->customerReference !== '') {
            $userUsage = CouponRedemption::query()
                ->where('coupon_id', $coupon->id)
                ->where('customer_reference', $cart->customerReference)
                ->whereIn('status', $activeStatuses)
                ->count();

            if ($userUsage >= $coupon->usage_limit_per_user) {
                return CouponEvaluation::invalid('USER_LIMIT_REACHED', 'You have reached the maximum usage limit for this coupon.', $coupon->code);
            }
        }

        if ($coupon->usage_limit_total !== null) {
            $globalUsage = CouponRedemption::query()
                ->where('coupon_id', $coupon->id)
                ->whereIn('status', $activeStatuses)
                ->count();

            if ($globalUsage >= $coupon->usage_limit_total) {
                return CouponEvaluation::invalid('GLOBAL_LIMIT_REACHED', 'This coupon has reached its maximum global usage limit.', $coupon->code);
            }
        }

        // 5. First-order only check
        if ($coupon->first_order_only && $this->customerEligibility) {
            $isFirst = $this->customerEligibility->isFirstOrder($cart->customerReference);
            if (! $isFirst) {
                return CouponEvaluation::invalid('FIRST_ORDER_ONLY', 'This coupon is valid only for your first order.', $coupon->code);
            }
        }

        // 6. Filter lines based on coupon scopes
        $eligibleLines = $this->filterEligibleLines($coupon, $cart->lines);

        if (empty($eligibleLines)) {
            return CouponEvaluation::invalid('NO_ELIGIBLE_ITEMS', 'None of the items in your cart are eligible for this coupon.', $coupon->code);
        }

        $eligibleSubtotal = array_sum(array_map(fn (CartLineContext $l) => $l->lineTotal, $eligibleLines));

        // 7. Minimum order amount check
        if ($eligibleSubtotal < $coupon->min_order_amount) {
            $minFormatted = number_format($coupon->min_order_amount / 100);

            return CouponEvaluation::invalid(
                'MIN_ORDER_NOT_MET',
                "Order total for eligible items must be at least ₹{$minFormatted} to apply this coupon.",
                $coupon->code
            );
        }

        // 8. Discount calculation
        $rawDiscount = 0;
        if ($coupon->discount_type === DiscountType::Percentage) {
            $rawDiscount = (int) floor(($eligibleSubtotal * $coupon->discount_value) / 100);
            if ($coupon->max_discount !== null && $coupon->max_discount > 0) {
                $rawDiscount = min($rawDiscount, $coupon->max_discount);
            }
        } else {
            $rawDiscount = min((int) $coupon->discount_value, $eligibleSubtotal);
        }

        // 9. Cap total discount so items total after discount stays at least MIN_PAYABLE_PAISE
        $minPayable = (int) config('coupons.min_payable_paise', self::MIN_PAYABLE_PAISE);
        $maxAllowableDiscount = max(0, $eligibleSubtotal - $minPayable);
        $totalDiscount = min($rawDiscount, $maxAllowableDiscount);

        if ($totalDiscount <= 0) {
            return CouponEvaluation::invalid(
                'DISCOUNT_NOT_FEASIBLE',
                'Discount cannot be applied as the order total after discount must be at least ₹1.00.',
                $coupon->code
            );
        }

        // 10. True largest-remainder line allocation (Hare-Niemeyer method)
        $lineAllocations = $this->allocateLargestRemainder($eligibleLines, $totalDiscount, $eligibleSubtotal);

        return new CouponEvaluation(
            isValid: true,
            errorCode: null,
            message: 'Coupon applied successfully.',
            totalDiscount: $totalDiscount,
            lineAllocations: $lineAllocations,
            fundedBy: $coupon->funded_by->value,
            couponId: $coupon->id,
            couponCode: $coupon->code,
        );
    }

    /**
     * @param  array<int, CartLineContext>  $lines
     * @return array<int, CartLineContext>
     */
    protected function filterEligibleLines(Coupon $coupon, array $lines): array
    {
        $scopes = $coupon->scopes;

        if ($scopes->isEmpty()) {
            return $lines;
        }

        // Group scopes by scope_type and rule_type
        $includes = [];
        $excludes = [];

        foreach ($scopes as $scope) {
            $type = $scope->scope_type->value;
            $id = (int) $scope->scope_id;
            if ($scope->rule_type === ScopeRuleType::Include) {
                $includes[$type][] = $id;
            } else {
                $excludes[$type][] = $id;
            }
        }

        $eligible = [];

        foreach ($lines as $line) {
            // Check Excludes first
            if (! empty($excludes[ScopeType::Product->value]) && in_array($line->productId, $excludes[ScopeType::Product->value], true)) {
                continue;
            }
            if (! empty($excludes[ScopeType::Seller->value]) && in_array($line->sellerId, $excludes[ScopeType::Seller->value], true)) {
                continue;
            }
            if ($line->brandId !== null && ! empty($excludes[ScopeType::Brand->value]) && in_array($line->brandId, $excludes[ScopeType::Brand->value], true)) {
                continue;
            }
            if (! empty($excludes[ScopeType::Category->value]) && ! empty(array_intersect($line->categoryIds, $excludes[ScopeType::Category->value]))) {
                continue;
            }

            // Check Includes (if an include scope group is defined, the line must match at least one in that group)
            if (! empty($includes[ScopeType::Product->value]) && ! in_array($line->productId, $includes[ScopeType::Product->value], true)) {
                continue;
            }
            if (! empty($includes[ScopeType::Seller->value]) && ! in_array($line->sellerId, $includes[ScopeType::Seller->value], true)) {
                continue;
            }
            if (! empty($includes[ScopeType::Brand->value]) && ($line->brandId === null || ! in_array($line->brandId, $includes[ScopeType::Brand->value], true))) {
                continue;
            }
            if (! empty($includes[ScopeType::Category->value]) && empty(array_intersect($line->categoryIds, $includes[ScopeType::Category->value]))) {
                continue;
            }

            $eligible[] = $line;
        }

        return $eligible;
    }

    /**
     * True largest-remainder line allocation:
     * 1. Exact share = (lineTotal * totalDiscount) / eligibleSubtotal
     * 2. Base = floor(exact share)
     * 3. Remainder = totalDiscount - sum(base)
     * 4. Sort by fractional part DESC, tie-break lineTotal DESC, then lineId ASC
     * 5. Add 1 paise to top R lines (capping at lineTotal)
     *
     * @param  array<int, CartLineContext>  $eligibleLines
     * @return array<string|int, int> [line_id => discount_paise]
     */
    protected function allocateLargestRemainder(array $eligibleLines, int $totalDiscount, int $eligibleSubtotal): array
    {
        if (empty($eligibleLines) || $totalDiscount <= 0 || $eligibleSubtotal <= 0) {
            return [];
        }

        $items = [];
        $allocatedSum = 0;

        foreach ($eligibleLines as $line) {
            $exactShare = ($line->lineTotal * $totalDiscount) / $eligibleSubtotal;
            $baseShare = min($line->lineTotal, (int) floor($exactShare));
            $fraction = $exactShare - floor($exactShare);

            $items[] = [
                'line_id' => $line->lineId,
                'line_total' => $line->lineTotal,
                'base' => $baseShare,
                'fraction' => $fraction,
            ];

            $allocatedSum += $baseShare;
        }

        $remainder = $totalDiscount - $allocatedSum;

        // Sort by fraction DESC, then line_total DESC, then line_id ASC
        usort($items, function (array $a, array $b) {
            // Compare fraction DESC (with small epsilon handling)
            if (abs($b['fraction'] - $a['fraction']) > 1e-9) {
                return $b['fraction'] <=> $a['fraction'];
            }
            // Tie-break 1: line_total DESC
            if ($b['line_total'] !== $a['line_total']) {
                return $b['line_total'] <=> $a['line_total'];
            }

            // Tie-break 2: line_id ASC
            return strcmp((string) $a['line_id'], (string) $b['line_id']);
        });

        // Distribute remainder paise
        $idx = 0;
        $count = count($items);
        while ($remainder > 0 && $idx < $count) {
            if ($items[$idx]['base'] < $items[$idx]['line_total']) {
                $items[$idx]['base'] += 1;
                $remainder--;
            }
            $idx++;
        }

        $result = [];
        foreach ($items as $item) {
            $result[$item['line_id']] = $item['base'];
        }

        return $result;
    }
}
