<?php

use App\Coupons\Contracts\CustomerEligibilityInterface;
use App\Coupons\DTOs\CartContext;
use App\Coupons\DTOs\CartLineContext;
use App\Coupons\Enums\CouponFundedBy;
use App\Coupons\Enums\CouponStatus;
use App\Coupons\Enums\DiscountType;
use App\Coupons\Enums\RedemptionStatus;
use App\Coupons\Enums\ScopeRuleType;
use App\Coupons\Enums\ScopeType;
use App\Coupons\Models\Coupon;
use App\Coupons\Models\CouponRedemption;
use App\Coupons\Models\CouponScope;
use App\Coupons\Services\CouponEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('percentage discount calculates and respects max discount cap', function () {
    $coupon = Coupon::create([
        'code' => 'SAVE20',
        'name' => '20% Off',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'max_discount' => 15000, // ₹150 cap
        'min_order_amount' => 0,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $evaluator = new CouponEvaluator;

    // Cart with 2 lines total ₹1000 (100,000 paise)
    // 20% would be ₹200 (20,000 paise), but capped at ₹150 (15,000 paise)
    $lines = [
        new CartLineContext(lineId: 1, sellerId: 10, productId: 101, categoryIds: [1], brandId: 5, unitPrice: 60000, quantity: 1, lineTotal: 60000),
        new CartLineContext(lineId: 2, sellerId: 11, productId: 102, categoryIds: [2], brandId: 6, unitPrice: 40000, quantity: 1, lineTotal: 40000),
    ];

    $cart = new CartContext(customerReference: '99', lines: $lines);
    $evaluation = $evaluator->evaluate($cart, 'SAVE20');

    expect($evaluation->isValid)->toBeTrue()
        ->and($evaluation->totalDiscount)->toBe(15000)
        ->and($evaluation->lineAllocations[1])->toBe(9000) // 60% of 15,000
        ->and($evaluation->lineAllocations[2])->toBe(6000) // 40% of 15,000
        ->and(array_sum($evaluation->lineAllocations))->toBe(15000);
});

test('worked example 2: largest remainder allocation distributes odd penny deterministically', function () {
    // Coupon: Flat ₹100.01 off (10,001 paise)
    $coupon = Coupon::create([
        'code' => 'FLAT100',
        'name' => 'Flat 100.01 Off',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 10001,
        'min_order_amount' => 0,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $evaluator = new CouponEvaluator;

    // 3 lines: Line 201 (33,333 paise), Line 202 (33,333 paise), Line 203 (33,334 paise)
    $lines = [
        new CartLineContext(lineId: 201, sellerId: 1, productId: 1, categoryIds: [1], brandId: 1, unitPrice: 33333, quantity: 1, lineTotal: 33333),
        new CartLineContext(lineId: 202, sellerId: 1, productId: 2, categoryIds: [1], brandId: 1, unitPrice: 33333, quantity: 1, lineTotal: 33333),
        new CartLineContext(lineId: 203, sellerId: 2, productId: 3, categoryIds: [1], brandId: 1, unitPrice: 33334, quantity: 1, lineTotal: 33334),
    ];

    $cart = new CartContext(customerReference: '1', lines: $lines);
    $evaluation = $evaluator->evaluate($cart, 'FLAT100');

    expect($evaluation->isValid)->toBeTrue()
        ->and($evaluation->totalDiscount)->toBe(10001)
        ->and(array_sum($evaluation->lineAllocations))->toBe(10001)
        ->and($evaluation->lineAllocations[203])->toBe(3334) // fraction 0.73334 gets 1st remainder paise
        ->and($evaluation->lineAllocations[201])->toBe(3334) // fraction 0.63333 (line_id 201 < 202 tie-break) gets 2nd remainder paise
        ->and($evaluation->lineAllocations[202])->toBe(3333);
});

test('rule 5: evaluator caps discount so items total after discount stays at least 100 paise', function () {
    // Big coupon: ₹500 off on a ₹500 order
    $coupon = Coupon::create([
        'code' => 'BIG500',
        'name' => '₹500 Off',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 50000, // 50,000 paise
        'min_order_amount' => 0,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $evaluator = new CouponEvaluator;

    // Line total is exactly 50,000 paise
    $lines = [
        new CartLineContext(lineId: 10, sellerId: 1, productId: 1, categoryIds: [1], brandId: null, unitPrice: 50000, quantity: 1, lineTotal: 50000),
    ];

    $cart = new CartContext(customerReference: '1', lines: $lines);
    $evaluation = $evaluator->evaluate($cart, 'BIG500');

    // Discount must be capped at 50,000 - 100 = 49,900 paise (payable stays 100 paise = ₹1.00)
    expect($evaluation->isValid)->toBeTrue()
        ->and($evaluation->totalDiscount)->toBe(49900)
        ->and($evaluation->lineAllocations[10])->toBe(49900);
});

test('evaluator rejects inactive, expired, and not-yet-started coupons', function () {
    $now = CarbonImmutable::parse('2026-06-01 12:00:00');

    $paused = Coupon::create([
        'code' => 'PAUSED',
        'name' => 'Paused',
        'status' => CouponStatus::Paused,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 5000,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $future = Coupon::create([
        'code' => 'FUTURE',
        'name' => 'Future',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 5000,
        'starts_at' => $now->addDays(5),
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $expired = Coupon::create([
        'code' => 'EXPIRED',
        'name' => 'Expired',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 5000,
        'expires_at' => $now->subDays(2),
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $evaluator = new CouponEvaluator;
    $lines = [
        new CartLineContext(lineId: 1, sellerId: 1, productId: 1, categoryIds: [1], brandId: null, unitPrice: 10000, quantity: 1, lineTotal: 10000),
    ];
    $cart = new CartContext(customerReference: '1', lines: $lines, now: $now);

    expect($evaluator->evaluate($cart, 'PAUSED')->isValid)->toBeFalse()
        ->and($evaluator->evaluate($cart, 'PAUSED')->errorCode)->toBe('INACTIVE_COUPON')
        ->and($evaluator->evaluate($cart, 'FUTURE')->isValid)->toBeFalse()
        ->and($evaluator->evaluate($cart, 'FUTURE')->errorCode)->toBe('NOT_YET_STARTED')
        ->and($evaluator->evaluate($cart, 'EXPIRED')->isValid)->toBeFalse()
        ->and($evaluator->evaluate($cart, 'EXPIRED')->errorCode)->toBe('EXPIRED');
});

test('evaluator filters lines using include and exclude scope rules', function () {
    $coupon = Coupon::create([
        'code' => 'CAT_ONLY',
        'name' => 'Category 1 Only',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    // Include category 1, exclude product 99
    CouponScope::create(['coupon_id' => $coupon->id, 'scope_type' => ScopeType::Category, 'scope_id' => 1, 'rule_type' => ScopeRuleType::Include]);
    CouponScope::create(['coupon_id' => $coupon->id, 'scope_type' => ScopeType::Product, 'scope_id' => 99, 'rule_type' => ScopeRuleType::Exclude]);

    $evaluator = new CouponEvaluator;

    $lines = [
        // Eligible: Category 1, product 10
        new CartLineContext(lineId: 1, sellerId: 1, productId: 10, categoryIds: [1], brandId: null, unitPrice: 20000, quantity: 1, lineTotal: 20000),
        // Ineligible: Category 2
        new CartLineContext(lineId: 2, sellerId: 1, productId: 20, categoryIds: [2], brandId: null, unitPrice: 30000, quantity: 1, lineTotal: 30000),
        // Ineligible: Excluded product 99 even though in category 1
        new CartLineContext(lineId: 3, sellerId: 1, productId: 99, categoryIds: [1], brandId: null, unitPrice: 10000, quantity: 1, lineTotal: 10000),
    ];

    $cart = new CartContext(customerReference: '1', lines: $lines);
    $evaluation = $evaluator->evaluate($cart, 'CAT_ONLY');

    expect($evaluation->isValid)->toBeTrue()
        ->and($evaluation->totalDiscount)->toBe(2000) // 10% of 20,000
        ->and($evaluation->lineAllocations)->toHaveKey(1)
        ->and($evaluation->lineAllocations)->not->toHaveKey(2)
        ->and($evaluation->lineAllocations)->not->toHaveKey(3);
});

test('evaluator enforces first-order-only customer eligibility via contract', function () {
    $coupon = Coupon::create([
        'code' => 'WELCOME',
        'name' => 'First Order',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 1000,
        'first_order_only' => true,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    // Mock customer eligibility: customer 10 is first order, customer 20 is not
    $eligibilityMock = new class implements CustomerEligibilityInterface
    {
        public function isFirstOrder(string $customerReference): bool
        {
            return $customerReference === '10';
        }
    };

    $evaluator = new CouponEvaluator(customerEligibility: $eligibilityMock);
    $lines = [
        new CartLineContext(lineId: 1, sellerId: 1, productId: 1, categoryIds: [1], brandId: null, unitPrice: 10000, quantity: 1, lineTotal: 10000),
    ];

    $firstOrderCart = new CartContext(customerReference: '10', lines: $lines);
    $repeatCart = new CartContext(customerReference: '20', lines: $lines);

    expect($evaluator->evaluate($firstOrderCart, 'WELCOME')->isValid)->toBeTrue()
        ->and($evaluator->evaluate($repeatCart, 'WELCOME')->isValid)->toBeFalse()
        ->and($evaluator->evaluate($repeatCart, 'WELCOME')->errorCode)->toBe('FIRST_ORDER_ONLY');
});

test('rule 4: evaluator rejects seller-funded coupons in this phase', function () {
    $coupon = new Coupon([
        'code' => 'SELLERFUND',
        'name' => 'Seller funded',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 1000,
        'min_order_amount' => 0,
        'funded_by' => CouponFundedBy::Seller,
    ]);
    // Save bypassed or direct evaluation check
    $evaluator = new CouponEvaluator;
    $lines = [
        new CartLineContext(lineId: 1, sellerId: 1, productId: 1, categoryIds: [1], brandId: null, unitPrice: 10000, quantity: 1, lineTotal: 10000),
    ];
    $cart = new CartContext(customerReference: '1', lines: $lines);

    $evaluation = $evaluator->evaluate($cart, 'SELLERFUND');
    expect($evaluation->isValid)->toBeFalse()
        ->and($evaluation->errorCode)->toBe('COUPON_NOT_FOUND');
});

test('coupon model saving hook rejects funded_by=seller with DomainException', function () {
    expect(function () {
        Coupon::create([
            'code' => 'SELLERFAIL',
            'name' => 'Seller Funded',
            'status' => CouponStatus::Active,
            'discount_type' => DiscountType::Fixed,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'funded_by' => CouponFundedBy::Seller,
        ]);
    })->toThrow(DomainException::class, 'Seller-funded coupons are not supported in this phase.');
});

test('partially_released redemptions still count toward global and per-user limits', function () {
    $coupon = Coupon::create([
        'code' => 'LIMIT1',
        'name' => 'Limit One',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 1000,
        'min_order_amount' => 0,
        'usage_limit_total' => 2,
        'usage_limit_per_user' => 1,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    // Customer 10 has a partially_released redemption
    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'coupon_code' => $coupon->code,
        'order_reference' => 'ORD-101',
        'customer_reference' => '10',
        'idempotency_key' => 'idemp-101',
        'status' => RedemptionStatus::PartiallyReleased,
        'discount_amount' => 1000,
        'released_amount' => 500,
        'line_allocations' => [1 => 1000],
        'redeemed_at' => now(),
    ]);

    $evaluator = new CouponEvaluator;
    $lines = [
        new CartLineContext(lineId: 1, sellerId: 1, productId: 1, categoryIds: [1], brandId: null, unitPrice: 10000, quantity: 1, lineTotal: 10000),
    ];

    // Customer 10 tries to use it again -> user limit reached
    $cartUser10 = new CartContext(customerReference: '10', lines: $lines);
    $eval10 = $evaluator->evaluate($cartUser10, 'LIMIT1');
    expect($eval10->isValid)->toBeFalse()
        ->and($eval10->errorCode)->toBe('USER_LIMIT_REACHED');

    // Customer 20 tries to use it -> valid (1 global use out of 2)
    $cartUser20 = new CartContext(customerReference: '20', lines: $lines);
    $eval20 = $evaluator->evaluate($cartUser20, 'LIMIT1');
    expect($eval20->isValid)->toBeTrue();

    // Now record a second redeemed redemption for user 20
    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'coupon_code' => $coupon->code,
        'order_reference' => 'ORD-102',
        'customer_reference' => '20',
        'idempotency_key' => 'idemp-102',
        'status' => RedemptionStatus::Redeemed,
        'discount_amount' => 1000,
        'released_amount' => 0,
        'line_allocations' => [1 => 1000],
        'redeemed_at' => now(),
    ]);

    // Customer 30 tries to use it -> global limit reached (2 out of 2)
    $cartUser30 = new CartContext(customerReference: '30', lines: $lines);
    $eval30 = $evaluator->evaluate($cartUser30, 'LIMIT1');
    expect($eval30->isValid)->toBeFalse()
        ->and($eval30->errorCode)->toBe('GLOBAL_LIMIT_REACHED');
});

test('fully released redemption does not count toward limits', function () {
    $coupon = Coupon::create([
        'code' => 'LIMITFREE',
        'name' => 'Limit Free',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 1000,
        'min_order_amount' => 0,
        'usage_limit_per_user' => 1,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'coupon_code' => $coupon->code,
        'order_reference' => 'ORD-999',
        'customer_reference' => '42',
        'idempotency_key' => 'idemp-999',
        'status' => RedemptionStatus::Released,
        'discount_amount' => 1000,
        'released_amount' => 1000,
        'line_allocations' => [1 => 1000],
        'redeemed_at' => now(),
    ]);

    $evaluator = new CouponEvaluator;
    $lines = [
        new CartLineContext(lineId: 1, sellerId: 1, productId: 1, categoryIds: [1], brandId: null, unitPrice: 10000, quantity: 1, lineTotal: 10000),
    ];
    $cart = new CartContext(customerReference: '42', lines: $lines);

    $eval = $evaluator->evaluate($cart, 'LIMITFREE');
    expect($eval->isValid)->toBeTrue();
});

test('rule 5: minimum-payable cap leaves at least 100 paise payable when discount would zero out subtotal', function () {
    // Coupon: Flat ₹50 off (5,000 paise)
    Coupon::create([
        'code' => 'BIGDISCOUNT',
        'name' => 'Big Discount',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 5000,
        'min_order_amount' => 0,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $evaluator = new CouponEvaluator;

    // Subtotal is ₹50 (5,000 paise).
    // Without cap, discount would be 5,000 paise and payable 0 paise.
    // With cap (min payable 100 paise), discount is capped to 4,900 paise.
    $lines = [
        new CartLineContext(lineId: 1, sellerId: 1, productId: 1, categoryIds: [1], brandId: null, unitPrice: 5000, quantity: 1, lineTotal: 5000),
    ];
    $cart = new CartContext(customerReference: '1', lines: $lines);

    $eval = $evaluator->evaluate($cart, 'BIGDISCOUNT');

    expect($eval->isValid)->toBeTrue()
        ->and($eval->totalDiscount)->toBe(4900)
        ->and($lines[0]->lineTotal - $eval->totalDiscount)->toBe(100);
});

test('rule 5: coupon that would make payable zero when subtotal is equal to or less than min payable is rejected', function () {
    Coupon::create([
        'code' => 'ZEROFAIL',
        'name' => 'Zero Fail',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 500,
        'min_order_amount' => 0,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $evaluator = new CouponEvaluator;

    // Subtotal is ₹1 (100 paise). With min payable 100 paise, max discount is 0, so discount cannot be applied.
    $lines = [
        new CartLineContext(lineId: 1, sellerId: 1, productId: 1, categoryIds: [1], brandId: null, unitPrice: 100, quantity: 1, lineTotal: 100),
    ];
    $cart = new CartContext(customerReference: '1', lines: $lines);

    $eval = $evaluator->evaluate($cart, 'ZEROFAIL');

    expect($eval->isValid)->toBeFalse()
        ->and($eval->errorCode)->toBe('DISCOUNT_NOT_FEASIBLE');
});

test('global feature switch disables coupon evaluation when coupons.enabled is false', function () {
    Coupon::create([
        'code' => 'ACTIVE10',
        'name' => 'Active 10',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 1000,
        'min_order_amount' => 0,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    config(['coupons.enabled' => false]);

    $evaluator = new CouponEvaluator;
    $lines = [
        new CartLineContext(lineId: 1, sellerId: 1, productId: 1, categoryIds: [1], brandId: null, unitPrice: 10000, quantity: 1, lineTotal: 10000),
    ];
    $cart = new CartContext(customerReference: '1', lines: $lines);

    $eval = $evaluator->evaluate($cart, 'ACTIVE10');

    expect($eval->isValid)->toBeFalse()
        ->and($eval->errorCode)->toBe('FEATURE_DISABLED');
});
