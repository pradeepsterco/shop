<?php

use App\Coupons\Contracts\CouponEvaluatorInterface;
use App\Coupons\Contracts\CouponRedemptionInterface;
use App\Coupons\DTOs\CartContext;
use App\Coupons\DTOs\CartLineContext;
use App\Coupons\DTOs\CouponEvaluation;
use App\Coupons\Enums\CouponFundedBy;
use App\Coupons\Enums\CouponStatus;
use App\Coupons\Enums\DiscountType;
use App\Coupons\Models\Coupon;
use App\Coupons\Models\CouponRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * The seller ledger books every sale at the full pre-coupon line total (SellerLedgerService, "Sale (Credit)"),
 * and refunds reverse that same full-price sale. That is only correct while every coupon is platform-funded.
 * These tests fail loudly if a seller-funded coupon can ever be saved, evaluated or redeemed; if that changes,
 * the seller's share of the discount must be debited in the ledger and in refund reversals first.
 */

beforeEach(function () {
    config(['coupons.enabled' => true]);
});

test('a seller-funded coupon cannot be saved', function () {
    expect(fn () => Coupon::create([
        'code' => 'SELLERFUND',
        'name' => 'Seller funded',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 5000,
        'funded_by' => CouponFundedBy::Seller,
    ]))->toThrow(DomainException::class, 'Seller-funded coupons are not supported');

    expect(Coupon::count())->toBe(0);
});

test('a seller-funded coupon that reaches the database anyway cannot be evaluated or redeemed', function () {
    // Bypass the model guard, as a raw import or future migration might
    $couponId = DB::table('coupons')->insertGetId([
        'code' => 'SELLERRAW',
        'name' => 'Seller funded (raw)',
        'status' => CouponStatus::Active->value,
        'discount_type' => DiscountType::Fixed->value,
        'discount_value' => 5000,
        'funded_by' => CouponFundedBy::Seller->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $evaluation = app(CouponEvaluatorInterface::class)->evaluate(
        new CartContext(customerReference: '1', lines: [
            new CartLineContext(lineId: 1, sellerId: 1, productId: 1, categoryIds: [1], brandId: 1, unitPrice: 50000, quantity: 1, lineTotal: 50000),
        ]),
        'SELLERRAW',
    );

    expect($evaluation->isValid)->toBeFalse()
        ->and($evaluation->errorCode)->toBe('SELLER_FUNDED_UNSUPPORTED');

    // Even a forged "valid" evaluation is refused at redemption
    $forged = new CouponEvaluation(
        isValid: true,
        errorCode: null,
        message: null,
        totalDiscount: 5000,
        lineAllocations: [1 => 5000],
        fundedBy: CouponFundedBy::Seller->value,
        couponId: $couponId,
        couponCode: 'SELLERRAW',
    );

    expect(fn () => app(CouponRedemptionInterface::class)->redeem($forged, '1', 'ORD-SELLER-FUNDED', 'idem-seller-funded'))
        ->toThrow(DomainException::class, 'Seller-funded coupons are not supported');

    expect(CouponRedemption::count())->toBe(0);
});
