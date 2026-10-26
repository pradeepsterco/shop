<?php

use App\Fees\DTOs\FeeBreakdownDto;
use App\Models\SellerProfile;
use App\Settlements\Contracts\SellerLedgerServiceInterface;
use App\Settlements\Enums\LedgerEntryType;
use App\Settlements\Enums\PayoutStatus;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Settlements\Models\SellerPayout;
use App\Settlements\Models\SellerPayoutBatch;
use App\Shared\Enums\ShippingZone;
use App\Shared\ValueObjects\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createMockFeeBreakdownDto(int $orderItemId): FeeBreakdownDto
{
    return new FeeBreakdownDto(
        sellingPrice: Money::fromPaise(100000), // ₹1,000.00
        qty: 1,
        lineTotal: Money::fromPaise(100000),
        taxableValue: Money::fromPaise(89286),
        commission: Money::fromPaise(10000), // ₹100.00
        closingFee: Money::fromPaise(2000),  // ₹20.00
        shippingFee: Money::fromPaise(5000), // ₹50.00
        collectionFee: Money::fromPaise(2000), // ₹20.00
        totalMarketplaceFees: Money::fromPaise(19000),
        gstOnFees: Money::fromPaise(3420), // 18% on ₹190 = ₹34.20 (3420 paise)
        tcs: Money::fromPaise(893), // 1% on ₹892.86 = ₹8.93 (893 paise)
        tds: Money::fromPaise(893), // 1% on ₹892.86 = ₹8.93 (893 paise)
        totalDeductions: Money::fromPaise(24206),
        netPayout: Money::fromPaise(75794), // ₹757.94
        claimableCredits: Money::fromPaise(5206),
        zone: ShippingZone::National,
        scheduleId: 1,
        scheduleVersion: 1,
        commissionRateBps: 1000,
        gstRateOnFees: 18.0,
        tcsRate: 1.0,
        tdsRate: 1.0,
        orderItemId: $orderItemId
    );
}

test('recordEntriesForSettlement creates exact signed ledger entries summing to net payout', function () {
    $seller = SellerProfile::factory()->approved()->create();
    $ledgerService = app(SellerLedgerServiceInterface::class);

    $settlement = SellerOrderSettlement::create([
        'seller_order_id' => 'SO-TEST-001',
        'order_id' => 'ORD-TEST-001',
        'seller_profile_id' => $seller->id,
        'gross_amount' => 100000,
        'total_deductions' => 24206,
        'net_payout' => 75794,
        'claimable_credits' => 5206,
        'status' => SettlementStatus::Pending,
        'return_window_days' => 7,
    ]);

    $snapshot = createMockFeeBreakdownDto(101);

    $ledgerService->recordEntriesForSettlement($settlement, [$snapshot]);

    $entries = SellerLedgerEntry::where('seller_order_id', 'SO-TEST-001')->get();
    expect($entries)->toHaveCount(8); // Sale, Commission, Closing, Shipping, Collection, GstOnFees, Tcs, Tds

    // Sale should be positive credit
    $sale = $entries->where('type', LedgerEntryType::Sale)->first();
    expect($sale->amount)->toBe(100000);

    // Commission should be negative debit
    $comm = $entries->where('type', LedgerEntryType::Commission)->first();
    expect($comm->amount)->toBe(-10000);

    // Sum of all entries must match exact net payout
    $totalLedgerSum = (int) $entries->sum('amount');
    expect($totalLedgerSum)->toBe(75794);

    // Idempotency: calling recordEntriesForSettlement again should not duplicate rows
    $ledgerService->recordEntriesForSettlement($settlement, [$snapshot]);
    expect(SellerLedgerEntry::where('seller_order_id', 'SO-TEST-001')->count())->toBe(8);
});

test('recordReversalsForSettlement creates exact opposite entries making ledger sum 0', function () {
    $seller = SellerProfile::factory()->approved()->create();
    $ledgerService = app(SellerLedgerServiceInterface::class);

    $settlement = SellerOrderSettlement::create([
        'seller_order_id' => 'SO-TEST-REV',
        'order_id' => 'ORD-TEST-REV',
        'seller_profile_id' => $seller->id,
        'gross_amount' => 100000,
        'total_deductions' => 24206,
        'net_payout' => 75794,
        'claimable_credits' => 5206,
        'status' => SettlementStatus::Pending,
        'return_window_days' => 7,
    ]);

    $snapshot = createMockFeeBreakdownDto(202);
    $ledgerService->recordEntriesForSettlement($settlement, [$snapshot]);

    // Initial sum is +75794
    expect((int) SellerLedgerEntry::where('seller_order_id', 'SO-TEST-REV')->sum('amount'))->toBe(75794);

    // Record reversals
    $ledgerService->recordReversalsForSettlement($settlement);

    // Entries count doubled (8 originals + 8 reversals)
    $allEntries = SellerLedgerEntry::where('seller_order_id', 'SO-TEST-REV')->get();
    expect($allEntries)->toHaveCount(16);

    // Total sum across all entries for this order package is exactly 0
    expect((int) $allEntries->sum('amount'))->toBe(0);

    // Idempotency: duplicate call to recordReversalsForSettlement does not duplicate reversals
    $ledgerService->recordReversalsForSettlement($settlement);
    expect(SellerLedgerEntry::where('seller_order_id', 'SO-TEST-REV')->count())->toBe(16);
});

test('getSellerBalance correctly computes on hold, eligible, in payout, and carried negative balances', function () {
    $seller = SellerProfile::factory()->approved()->create();
    $ledgerService = app(SellerLedgerServiceInterface::class);

    // 1. On hold settlement: ₹500 (50,000 paise)
    SellerOrderSettlement::create([
        'seller_order_id' => 'SO-HOLD',
        'order_id' => 'ORD-1',
        'seller_profile_id' => $seller->id,
        'gross_amount' => 60000,
        'total_deductions' => 10000,
        'net_payout' => 50000,
        'claimable_credits' => 1000,
        'status' => SettlementStatus::OnHold,
        'return_window_days' => 7,
    ]);

    // 2. Eligible settlement: ₹1,500 (150,000 paise)
    SellerOrderSettlement::create([
        'seller_order_id' => 'SO-ELIGIBLE',
        'order_id' => 'ORD-2',
        'seller_profile_id' => $seller->id,
        'gross_amount' => 180000,
        'total_deductions' => 30000,
        'net_payout' => 150000,
        'claimable_credits' => 3000,
        'status' => SettlementStatus::Eligible,
        'return_window_days' => 7,
    ]);

    // 3. Payout in processing batch: ₹800 (80,000 paise)
    $batch = SellerPayoutBatch::create([
        'batch_number' => 'BATCH-TEST-001',
        'cutoff_date' => now()->toDateString(),
        'status' => 'processing',
        'total_sellers_count' => 1,
        'total_amount' => 80000,
    ]);

    SellerPayout::create([
        'payout_batch_id' => $batch->id,
        'seller_profile_id' => $seller->id,
        'gross_settlement_amount' => 80000,
        'carried_balance_deduction' => 0,
        'net_amount' => 80000,
        'status' => PayoutStatus::Processing,
        'bank_account_holder' => 'Seller Store',
        'bank_account_number_masked' => '•••• 1234',
        'bank_ifsc' => 'HDFC0001234',
        'bank_fingerprint' => 'test-fingerprint',
    ]);

    // 4. Completed lifetime payout: ₹2,000 (200,000 paise)
    $completedBatch = SellerPayoutBatch::create([
        'batch_number' => 'BATCH-TEST-002',
        'cutoff_date' => now()->subWeek()->toDateString(),
        'status' => 'completed',
        'total_sellers_count' => 1,
        'total_amount' => 200000,
    ]);

    SellerPayout::create([
        'payout_batch_id' => $completedBatch->id,
        'seller_profile_id' => $seller->id,
        'gross_settlement_amount' => 200000,
        'carried_balance_deduction' => 0,
        'net_amount' => 200000,
        'status' => PayoutStatus::Paid,
        'bank_account_holder' => 'Seller Store',
        'bank_account_number_masked' => '•••• 1234',
        'bank_ifsc' => 'HDFC0001234',
        'bank_fingerprint' => 'test-fingerprint',
        'utr' => 'UTR123456789',
        'paid_at' => now()->subWeek(),
    ]);

    $balance = $ledgerService->getSellerBalance($seller->id);

    expect($balance->onHoldPaise)->toBe(50000)
        ->and($balance->eligiblePaise)->toBe(150000)
        ->and($balance->inPayoutPaise)->toBe(80000)
        ->and($balance->lifetimePaidPaise)->toBe(200000)
        ->and($balance->carriedNegativePaise)->toBe(0);

    // Now insert a negative adjustment (e.g. chargeback/return penalty post payout): -₹200 (-20,000 paise)
    SellerLedgerEntry::create([
        'seller_profile_id' => $seller->id,
        'seller_order_id' => null,
        'order_item_id' => null,
        'payout_id' => null,
        'type' => LedgerEntryType::Adjustment,
        'amount' => -20000,
        'description' => 'Return penalty adjustment',
        'source_key' => 'adjustment:test:001',
        'created_at' => now(),
    ]);

    $balanceAfterAdjustment = $ledgerService->getSellerBalance($seller->id);
    expect($balanceAfterAdjustment->carriedNegativePaise)->toBe(20000)
        // Eligible amount reduced by carried negative balance: ₹1,500 - ₹200 = ₹1,300 (130,000 paise)
        ->and($balanceAfterAdjustment->eligiblePaise)->toBe(130000);
});
