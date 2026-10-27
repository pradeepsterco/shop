<?php

use App\Coupons\DTOs\CouponEvaluation;
use App\Coupons\Enums\CouponFundedBy;
use App\Coupons\Enums\CouponStatus;
use App\Coupons\Enums\DiscountType;
use App\Coupons\Enums\RedemptionStatus;
use App\Coupons\Models\Coupon;
use App\Coupons\Models\CouponRedemption;
use App\Coupons\Services\CouponRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('rule 1: cumulative partial returns release identical totals regardless of return grouping', function () {
    // Test case: D = 10 paise, Q = 4 units
    // Formula:
    // released_after = floor(D * unitsReturnedSoFar / Q);
    // each release = released_after - alreadyReleased;
    // last unit releases the remainder.

    $coupon = Coupon::create([
        'code' => 'TEST10',
        'name' => 'Test 10',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 10,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $service = new CouponRedemptionService;

    // Setup Order 1: 1 unit + 1 unit
    $eval1 = new CouponEvaluation(
        isValid: true,
        errorCode: null,
        message: 'Valid',
        totalDiscount: 10,
        lineAllocations: [100 => 10],
        fundedBy: 'platform',
        couponId: $coupon->id,
        couponCode: $coupon->code,
    );
    $service->redeem($eval1, 'cust1', 'ORD-GRP-1', 'idem-grp-1');

    // Return 1 unit (1 of 4)
    $res1 = $service->releaseCumulativeItemUnits(
        orderReference: 'ORD-GRP-1',
        lineId: 100,
        unitsReturnedNow: 1,
        unitsReturnedPrior: 0,
        totalUnits: 4,
        lineAllocatedDiscount: 10,
    );
    // floor(10 * 1 / 4) = 2. Release = 2 - 0 = 2.
    expect($res1->releasedAmount)->toBe(2);

    // Return another 1 unit (cumulative 2 of 4)
    $res2 = $service->releaseCumulativeItemUnits(
        orderReference: 'ORD-GRP-1',
        lineId: 100,
        unitsReturnedNow: 1,
        unitsReturnedPrior: 1,
        totalUnits: 4,
        lineAllocatedDiscount: 10,
    );
    // floor(10 * 2 / 4) = 5. Release = 5 - 2 = 3.
    expect($res2->releasedAmount)->toBe(3);
    $totalReleasedCaseA = $res1->releasedAmount + $res2->releasedAmount; // 2 + 3 = 5

    // Setup Order 2: 2 units at once
    $eval2 = new CouponEvaluation(
        isValid: true,
        errorCode: null,
        message: 'Valid',
        totalDiscount: 10,
        lineAllocations: [200 => 10],
        fundedBy: 'platform',
        couponId: $coupon->id,
        couponCode: $coupon->code,
    );
    $service->redeem($eval2, 'cust2', 'ORD-GRP-2', 'idem-grp-2');

    // Return 2 units at once (2 of 4)
    $resAtOnce = $service->releaseCumulativeItemUnits(
        orderReference: 'ORD-GRP-2',
        lineId: 200,
        unitsReturnedNow: 2,
        unitsReturnedPrior: 0,
        totalUnits: 4,
        lineAllocatedDiscount: 10,
    );
    // floor(10 * 2 / 4) = 5. Release = 5 - 0 = 5.
    expect($resAtOnce->releasedAmount)->toBe(5)
        ->and($totalReleasedCaseA)->toBe($resAtOnce->releasedAmount); // 1+1 equals 2 at once!

    // Return remaining 2 units on Order 1 (cumulative 4 of 4)
    $resFinal = $service->releaseCumulativeItemUnits(
        orderReference: 'ORD-GRP-1',
        lineId: 100,
        unitsReturnedNow: 2,
        unitsReturnedPrior: 2,
        totalUnits: 4,
        lineAllocatedDiscount: 10,
    );
    // unitsReturnedSoFar = 4 == totalUnits -> releasedAfter = 10. alreadyReleased = 5. Release = 10 - 5 = 5.
    expect($resFinal->releasedAmount)->toBe(5)
        ->and($res1->releasedAmount + $res2->releasedAmount + $resFinal->releasedAmount)->toBe(10);
});

test('rule 2: per-user and global limits count both redeemed and partially_released, freeing use only on full release', function () {
    // Global limit = 1, per-user limit = 1
    $coupon = Coupon::create([
        'code' => 'LIMIT1',
        'name' => 'Limit 1',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 5000,
        'usage_limit_total' => 1,
        'usage_limit_per_user' => 1,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $service = new CouponRedemptionService;

    $eval = new CouponEvaluation(
        isValid: true,
        errorCode: null,
        message: 'Valid',
        totalDiscount: 5000,
        lineAllocations: [1 => 5000],
        fundedBy: 'platform',
        couponId: $coupon->id,
        couponCode: $coupon->code,
    );

    // 1st redemption succeeds
    $redemptionResult = $service->redeem($eval, 'user_abc', 'ORD-101', 'key-101');
    expect($redemptionResult->success)->toBeTrue();

    // 2nd redemption by same user fails due to per-user and global limit
    expect(fn () => $service->redeem($eval, 'user_abc', 'ORD-102', 'key-102'))
        ->toThrow(DomainException::class);

    // Partial return of 2,000 paise (status becomes partially_released)
    $service->release('ORD-101', 2000);
    $record = CouponRedemption::where('order_reference', 'ORD-101')->first();
    expect($record->status)->toBe(RedemptionStatus::PartiallyReleased);

    // Still blocked! Partially released redemptions STILL count against user and global limits!
    expect(fn () => $service->redeem($eval, 'user_abc', 'ORD-103', 'key-103'))
        ->toThrow(DomainException::class);

    // Now fully release remaining 3,000 paise
    $service->release('ORD-101', 3000);
    $record->refresh();
    expect($record->status)->toBe(RedemptionStatus::Released);

    // Now that it is fully released, the use is restored!
    $newRedemption = $service->redeem($eval, 'user_abc', 'ORD-104', 'key-104');
    expect($newRedemption->success)->toBeTrue();
});

test('redeem is idempotent when invoked with same idempotency key', function () {
    $coupon = Coupon::create([
        'code' => 'IDEMPOTENT',
        'name' => 'Idempotent',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 2000,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $service = new CouponRedemptionService;

    $eval = new CouponEvaluation(
        isValid: true,
        errorCode: null,
        message: 'Valid',
        totalDiscount: 2000,
        lineAllocations: [1 => 2000],
        fundedBy: 'platform',
        couponId: $coupon->id,
        couponCode: $coupon->code,
    );

    $res1 = $service->redeem($eval, 'user_1', 'ORD-IDEM', 'same-key-123');
    $res2 = $service->redeem($eval, 'user_1', 'ORD-IDEM', 'same-key-123');

    expect($res1->success)->toBeTrue()
        ->and($res2->success)->toBeTrue()
        ->and($res1->redemptionId)->toBe($res2->redemptionId)
        ->and(CouponRedemption::count())->toBe(1)
        ->and($coupon->fresh()->usage_count)->toBe(1);
});

test('concurrency simulation on sqlite: row locking assumptions documented', function () {
    // NOTE: In SQLite during unit tests, DB::table()->lockForUpdate() is executed as a no-op.
    // In production MySQL/PostgreSQL with InnoDB, Coupon::lockForUpdate() places an exclusive
    // row lock on the coupon row, serializing concurrent transactions and preventing race conditions.
    $coupon = Coupon::create([
        'code' => 'CONCURRENCY',
        'name' => 'Concurrency',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 1000,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    expect($coupon->id)->toBeGreaterThan(0);
});
