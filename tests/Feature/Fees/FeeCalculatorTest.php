<?php

use App\Fees\Contracts\FeeCalculatorInterface;
use App\Fees\DTOs\FeeQuoteInput;
use App\Fees\Models\FeeClosingSlab;
use App\Fees\Models\FeeCollectionRule;
use App\Fees\Models\FeeCommissionSlab;
use App\Fees\Models\FeeSchedule;
use App\Fees\Models\FeeShippingSlab;
use App\Shared\Enums\FeeScheduleStatus;
use App\Shared\Enums\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createStandardFeeSchedule(): FeeSchedule
{
    $schedule = FeeSchedule::create([
        'name' => 'Standard Schedule',
        'version' => 1,
        'status' => FeeScheduleStatus::Active,
        'effective_from' => now()->subDay(),
        'effective_to' => null,
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);

    // Fallback Commission: 10%
    FeeCommissionSlab::create([
        'fee_schedule_id' => $schedule->id,
        'vertical_id' => null,
        'min_price' => 0,
        'max_price' => null,
        'rate_bps' => 1000, // 10.00%
    ]);

    // Fixed Closing Fee: ₹20 (2000 paise)
    FeeClosingSlab::create([
        'fee_schedule_id' => $schedule->id,
        'min_price' => 0,
        'max_price' => null,
        'fee_amount' => 2000,
    ]);

    // Shipping Slabs
    FeeShippingSlab::create([
        'fee_schedule_id' => $schedule->id,
        'min_weight_grams' => 0,
        'max_weight_grams' => 500,
        'zone' => ShippingZone::Local,
        'fee_amount' => 3000, // ₹30
        'is_default' => true,
    ]);
    FeeShippingSlab::create([
        'fee_schedule_id' => $schedule->id,
        'min_weight_grams' => 0,
        'max_weight_grams' => 500,
        'zone' => ShippingZone::Regional,
        'fee_amount' => 4000, // ₹40
        'is_default' => true,
    ]);
    FeeShippingSlab::create([
        'fee_schedule_id' => $schedule->id,
        'min_weight_grams' => 0,
        'max_weight_grams' => 500,
        'zone' => ShippingZone::National,
        'fee_amount' => 5000, // ₹50
        'is_default' => true,
    ]);
    FeeShippingSlab::create([
        'fee_schedule_id' => $schedule->id,
        'min_weight_grams' => 501,
        'max_weight_grams' => 1500,
        'zone' => ShippingZone::National,
        'fee_amount' => 9000, // ₹90
        'is_default' => false,
    ]);

    // Collection Rule: 0% for cod
    FeeCollectionRule::create([
        'fee_schedule_id' => $schedule->id,
        'payment_method' => 'cod',
        'rate_bps' => 0,
        'fee_amount' => 0,
    ]);

    return $schedule;
}

test('reproduces exact worked example from specification', function () {
    createStandardFeeSchedule();

    /** @var FeeCalculatorInterface $calculator */
    $calculator = app(FeeCalculatorInterface::class);

    // Worked Example: ₹1,000 (12% GST), 10% commission, ₹20 fixed, ₹50 shipping, 0% collection, 18% GST on fees, TCS 0.5%, TDS 0.1%
    $input = new FeeQuoteInput(
        pricePaise: 100000, // ₹1,000.00
        productGstRate: 12.0,
        verticalId: null,
        weightGrams: 500,
        sellerPickupState: 'Maharashtra',
        sellerPickupPincode: '400001',
        deliveryState: 'Karnataka', // National
        deliveryPincode: '560001',
        paymentMethod: 'cod',
        sellerHasPan: true,
        effectiveDate: now(),
        qty: 1,
    );

    $breakdown = $calculator->quote($input);

    expect($breakdown->sellingPrice->getPaise())->toBe(100000)
        ->and($breakdown->commission->getPaise())->toBe(10000)      // ₹100.00
        ->and($breakdown->closingFee->getPaise())->toBe(2000)       // ₹20.00
        ->and($breakdown->shippingFee->getPaise())->toBe(5000)      // ₹50.00
        ->and($breakdown->collectionFee->getPaise())->toBe(0)       // ₹0.00
        ->and($breakdown->gstOnFees->getPaise())->toBe(3060)        // ₹30.60 (18% on 170.00)
        ->and($breakdown->tcs->getPaise())->toBe(446)              // ₹4.46 (0.5% on 892.86)
        ->and($breakdown->tds->getPaise())->toBe(100)              // ₹1.00 (0.1% on 1000.00)
        ->and($breakdown->totalDeductions->getPaise())->toBe(20606) // ₹206.06
        ->and($breakdown->netPayout->getPaise())->toBe(79394)       // ₹793.94
        ->and($breakdown->claimableCredits->getPaise())->toBe(3606); // ₹36.06
});

test('applies 5 percent TDS when seller has no PAN', function () {
    createStandardFeeSchedule();

    /** @var FeeCalculatorInterface $calculator */
    $calculator = app(FeeCalculatorInterface::class);

    $input = new FeeQuoteInput(
        pricePaise: 100000, // ₹1,000.00
        productGstRate: 12.0,
        sellerHasPan: false, // NO PAN
        weightGrams: 500,
        effectiveDate: now(),
    );

    $breakdown = $calculator->quote($input);

    // TDS becomes 5% of gross (₹50.00 = 5000 paise)
    expect($breakdown->tds->getPaise())->toBe(5000)
        ->and($breakdown->totalDeductions->getPaise())->toBe(25506)
        ->and($breakdown->netPayout->getPaise())->toBe(74494);
});

test('handles slab boundaries correctly at min and max edges', function () {
    $schedule = FeeSchedule::create([
        'name' => 'Edge Schedule',
        'version' => 1,
        'status' => FeeScheduleStatus::Active,
        'effective_from' => now()->subDay(),
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);

    // Slab 1: ₹0 to ₹500 (0 to 50000 paise) -> 12%
    // Slab 2: ₹500.01 to ₹1000 (50001 to 100000 paise) -> 10%
    FeeCommissionSlab::create([
        'fee_schedule_id' => $schedule->id,
        'min_price' => 0,
        'max_price' => 50000,
        'rate_bps' => 1200,
    ]);
    FeeCommissionSlab::create([
        'fee_schedule_id' => $schedule->id,
        'min_price' => 50001,
        'max_price' => 100000,
        'rate_bps' => 1000,
    ]);
    FeeClosingSlab::create([
        'fee_schedule_id' => $schedule->id,
        'min_price' => 0,
        'max_price' => null,
        'fee_amount' => 1000,
    ]);
    FeeShippingSlab::create([
        'fee_schedule_id' => $schedule->id,
        'min_weight_grams' => 0,
        'max_weight_grams' => null,
        'zone' => ShippingZone::National,
        'fee_amount' => 5000,
        'is_default' => true,
    ]);

    /** @var FeeCalculatorInterface $calculator */
    $calculator = app(FeeCalculatorInterface::class);

    // Boundary 1: Exactly 50,000 paise (₹500.00) -> matches Slab 1 (12%)
    $inputAt500 = new FeeQuoteInput(pricePaise: 50000, productGstRate: 0.0);
    $quote500 = $calculator->quote($inputAt500);
    expect($quote500->commissionRateBps)->toBe(1200)
        ->and($quote500->commission->getPaise())->toBe(6000); // 12% of 500.00 = 60.00

    // Boundary 2: Exactly 50,001 paise (₹500.01) -> matches Slab 2 (10%)
    $inputAt50001 = new FeeQuoteInput(pricePaise: 50001, productGstRate: 0.0);
    $quote50001 = $calculator->quote($inputAt50001);
    expect($quote50001->commissionRateBps)->toBe(1000)
        ->and($quote50001->commission->getPaise())->toBe(5000); // 10% of 500.01 rounded = 50.00
});

test('correctly determines shipping zone based on pincode and state', function () {
    createStandardFeeSchedule();

    /** @var FeeCalculatorInterface $calculator */
    $calculator = app(FeeCalculatorInterface::class);

    // 1. Local: First 3 digits match ('110001' and '110045')
    $localZone = $calculator->determineZone(
        pickupState: 'Delhi',
        pickupPincode: '110001',
        deliveryState: 'Delhi',
        deliveryPincode: '110045'
    );
    expect($localZone)->toBe(ShippingZone::Local);

    // 2. Regional: Same state, different first 3 digits ('400001' Mumbai vs '411001' Pune)
    $regionalZone = $calculator->determineZone(
        pickupState: 'Maharashtra',
        pickupPincode: '400001',
        deliveryState: 'Maharashtra',
        deliveryPincode: '411001'
    );
    expect($regionalZone)->toBe(ShippingZone::Regional);

    // 3. National: Different states
    $nationalZone = $calculator->determineZone(
        pickupState: 'Maharashtra',
        pickupPincode: '400001',
        deliveryState: 'Karnataka',
        deliveryPincode: '560001'
    );
    expect($nationalZone)->toBe(ShippingZone::National);

    // 4. Missing delivery pincode/state -> National fallback
    $missingZone = $calculator->determineZone(
        pickupState: 'Maharashtra',
        pickupPincode: '400001',
        deliveryState: null,
        deliveryPincode: null
    );
    expect($missingZone)->toBe(ShippingZone::National);
});

test('falls back to default weight slab when item weight is missing', function () {
    createStandardFeeSchedule();

    /** @var FeeCalculatorInterface $calculator */
    $calculator = app(FeeCalculatorInterface::class);

    $inputWithoutWeight = new FeeQuoteInput(
        pricePaise: 100000,
        productGstRate: 12.0,
        weightGrams: null, // missing weight
        sellerPickupState: 'Maharashtra',
        sellerPickupPincode: '400001',
        deliveryState: 'Karnataka',
        deliveryPincode: '560001',
    );

    $breakdown = $calculator->quote($inputWithoutWeight);

    // Default weight is 500g -> National 0-500g slab = ₹50 (5000 paise)
    expect($breakdown->shippingFee->getPaise())->toBe(5000);
});

test('3-item seller package charged one shipping fee with exact allocation sum', function () {
    createStandardFeeSchedule();

    /** @var FeeCalculatorInterface $calculator */
    $calculator = app(FeeCalculatorInterface::class);

    // 3 items in the same seller package:
    // Item 1: 300g, ₹500
    // Item 2: 400g, ₹800
    // Item 3: 500g, ₹1,200
    // Total package weight = 1,200g
    // National slab for 501-1500g is ₹90 (9000 paise).
    $items = [
        new FeeQuoteInput(pricePaise: 50000, productGstRate: 12.0, weightGrams: 300, qty: 1),
        new FeeQuoteInput(pricePaise: 80000, productGstRate: 12.0, weightGrams: 400, qty: 1),
        new FeeQuoteInput(pricePaise: 120000, productGstRate: 12.0, weightGrams: 500, qty: 1),
    ];

    $breakdowns = $calculator->calculateForSellerPackage(
        items: $items,
        pickupState: 'Maharashtra',
        pickupPincode: '400001',
        deliveryState: 'Karnataka',
        deliveryPincode: '560001',
        paymentMethod: 'cod',
        sellerHasPan: true,
        effectiveDate: now(),
    );

    expect($breakdowns)->toHaveCount(3);

    // Total allocated shipping across items must equal exactly 9000 paise (₹90)
    $allocatedShippingSum = $breakdowns[0]->shippingFee->getPaise()
        + $breakdowns[1]->shippingFee->getPaise()
        + $breakdowns[2]->shippingFee->getPaise();

    expect($allocatedShippingSum)->toBe(9000);

    // Check individual proportional allocations:
    // Item 1: 300/1200 = 25% of 9000 = 2250 paise
    // Item 2: 400/1200 = 33.333% of 9000 = 3000 paise
    // Item 3: Remainder = 9000 - (2250 + 3000) = 3750 paise
    expect($breakdowns[0]->shippingFee->getPaise())->toBe(2250)
        ->and($breakdowns[1]->shippingFee->getPaise())->toBe(3000)
        ->and($breakdowns[2]->shippingFee->getPaise())->toBe(3750);
});
