<?php

use App\Models\SellerProfile;
use App\Models\User;
use App\Settlements\Contracts\PayoutServiceInterface;
use App\Settlements\DTOs\PayoutBatchCreateInput;
use App\Settlements\Enums\LedgerEntryType;
use App\Settlements\Enums\PayoutStatus;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Settlements\Models\SellerPayout;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createTestSellerWithBank(string $status = 'approved', bool $verifiedBank = true): SellerProfile
{
    $user = User::factory()->create();

    $profile = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => $status,
        'store_name' => 'Store '.rand(100, 999),
        'bank_account_holder' => 'Beneficiary Name',
        'bank_account_number' => '123456789012',
        'ifsc' => 'HDFC0001234',
        'reviewed_at' => now()->subDays(10),
        'bank_updated_at' => $verifiedBank ? now()->subDays(12) : now()->subHour(), // Unverified if updated after reviewed_at
    ]);

    return $profile;
}

test('single open batch constraint prevents concurrent open batches', function () {
    $seller = createTestSellerWithBank();
    $payoutService = app(PayoutServiceInterface::class);

    // Create an eligible settlement of ₹2,000 (above ₹1,000 threshold)
    SellerOrderSettlement::create([
        'seller_order_id' => 'SO-BATCH-1',
        'order_id' => 'ORD-1',
        'seller_profile_id' => $seller->id,
        'gross_amount' => 250000,
        'total_deductions' => 50000,
        'net_payout' => 200000,
        'claimable_credits' => 5000,
        'status' => SettlementStatus::Eligible,
        'return_window_days' => 7,
    ]);

    $input = new PayoutBatchCreateInput(scheduledPayoutDate: CarbonImmutable::now());
    $result = $payoutService->createWeeklyPayoutBatch($input);

    expect($result->batch)->not->toBeNull()
        ->and($result->payoutCount)->toBe(1);

    // Attempting to create a second batch while one is draft must throw RuntimeException
    expect(fn () => $payoutService->createWeeklyPayoutBatch($input))
        ->toThrow(RuntimeException::class, 'An open payout batch already exists');
});

test('bank details changed since verification are excluded from batch with reason', function () {
    // Seller with bank updated after reviewed_at
    $unverifiedSeller = createTestSellerWithBank(verifiedBank: false);
    $payoutService = app(PayoutServiceInterface::class);

    $settlement = SellerOrderSettlement::create([
        'seller_order_id' => 'SO-UNVERIFIED',
        'order_id' => 'ORD-UNVERIFIED',
        'seller_profile_id' => $unverifiedSeller->id,
        'gross_amount' => 300000,
        'total_deductions' => 50000,
        'net_payout' => 250000,
        'claimable_credits' => 5000,
        'status' => SettlementStatus::Eligible,
        'return_window_days' => 7,
    ]);

    $input = new PayoutBatchCreateInput(scheduledPayoutDate: CarbonImmutable::now());
    $result = $payoutService->createWeeklyPayoutBatch($input);

    expect($result->batch)->toBeNull()
        ->and($result->payoutCount)->toBe(0)
        ->and($result->excludedSellers)->toHaveCount(1)
        ->and($result->excludedSellers[0]['reason'])->toBe('held: bank details pending verification');

    // Settlement remains in eligible status
    $settlement->refresh();
    expect($settlement->status)->toBe(SettlementStatus::Eligible);
});

test('bank fingerprint verification rejects CSV export if seller bank account was altered', function () {
    $seller = createTestSellerWithBank();
    $payoutService = app(PayoutServiceInterface::class);

    SellerOrderSettlement::create([
        'seller_order_id' => 'SO-FINGERPRINT',
        'order_id' => 'ORD-FP',
        'seller_profile_id' => $seller->id,
        'gross_amount' => 200000,
        'total_deductions' => 30000,
        'net_payout' => 170000,
        'claimable_credits' => 3000,
        'status' => SettlementStatus::Eligible,
        'return_window_days' => 7,
    ]);

    $input = new PayoutBatchCreateInput(scheduledPayoutDate: CarbonImmutable::now());
    $result = $payoutService->createWeeklyPayoutBatch($input);
    $batch = $result->batch;
    expect($batch)->not->toBeNull();

    // 1. Export when bank details are unchanged succeeds and outputs standard CSV format
    $csv = $payoutService->generateBankCsv($batch->id);
    expect($csv)->toContain('Beneficiary Name')
        ->and($csv)->toContain('Account Number')
        ->and($csv)->toContain('123456789012')
        ->and($csv)->toContain('1700.00');

    // 2. Now simulate malicious or unverified alteration of seller's bank account directly
    $seller->update(['bank_account_number' => '999999999999']);

    // Generate bank CSV must throw security verification exception
    expect(fn () => $payoutService->generateBankCsv($batch->id))
        ->toThrow(RuntimeException::class, 'Security verification failed');
});

test('non-approved and anonymized sellers are excluded from payout batches', function () {
    $suspendedSeller = createTestSellerWithBank(status: 'suspended');
    $payoutService = app(PayoutServiceInterface::class);

    SellerOrderSettlement::create([
        'seller_order_id' => 'SO-SUSPENDED',
        'order_id' => 'ORD-SUSP',
        'seller_profile_id' => $suspendedSeller->id,
        'gross_amount' => 200000,
        'total_deductions' => 30000,
        'net_payout' => 170000,
        'claimable_credits' => 3000,
        'status' => SettlementStatus::Eligible,
        'return_window_days' => 7,
    ]);

    $input = new PayoutBatchCreateInput(scheduledPayoutDate: CarbonImmutable::now());
    $result = $payoutService->createWeeklyPayoutBatch($input);

    expect($result->batch)->toBeNull()
        ->and($result->excludedSellers)->toHaveCount(1)
        ->and($result->excludedSellers[0]['reason'])->toContain("Seller status is 'suspended'");
});

test('markPayoutPaid marks payout and settlements paid and writes ledger debit', function () {
    $seller = createTestSellerWithBank();
    $payoutService = app(PayoutServiceInterface::class);

    $settlement = SellerOrderSettlement::create([
        'seller_order_id' => 'SO-PAID-TEST',
        'order_id' => 'ORD-PAID',
        'seller_profile_id' => $seller->id,
        'gross_amount' => 200000,
        'total_deductions' => 30000,
        'net_payout' => 170000,
        'claimable_credits' => 3000,
        'status' => SettlementStatus::Eligible,
        'return_window_days' => 7,
    ]);

    $input = new PayoutBatchCreateInput(scheduledPayoutDate: CarbonImmutable::now());
    $result = $payoutService->createWeeklyPayoutBatch($input);
    $batch = $result->batch;
    $payout = SellerPayout::where('payout_batch_id', $batch->id)->firstOrFail();

    $payoutService->markPayoutPaid($payout->id, 'HDFC20261025999');

    $payout->refresh();
    $settlement->refresh();
    $batch->refresh();

    expect($payout->status)->toBe(PayoutStatus::Paid)
        ->and($payout->utr)->toBe('HDFC20261025999')
        ->and($payout->paid_at)->not->toBeNull()
        ->and($settlement->status)->toBe(SettlementStatus::Paid)
        ->and($settlement->paid_at)->not->toBeNull()
        ->and($batch->status)->toBe('completed');

    // Ledger entry for payout disbursement
    $ledgerEntry = SellerLedgerEntry::where('source_key', "payout:{$payout->id}")->first();
    expect($ledgerEntry)->not->toBeNull()
        ->and($ledgerEntry->type)->toBe(LedgerEntryType::Payout)
        ->and($ledgerEntry->amount)->toBe(-170000);
});

test('markPayoutFailed sets payout failed and reverts settlements to eligible', function () {
    $seller = createTestSellerWithBank();
    $payoutService = app(PayoutServiceInterface::class);

    $settlement = SellerOrderSettlement::create([
        'seller_order_id' => 'SO-FAIL-TEST',
        'order_id' => 'ORD-FAIL',
        'seller_profile_id' => $seller->id,
        'gross_amount' => 200000,
        'total_deductions' => 30000,
        'net_payout' => 170000,
        'claimable_credits' => 3000,
        'status' => SettlementStatus::Eligible,
        'return_window_days' => 7,
    ]);

    $input = new PayoutBatchCreateInput(scheduledPayoutDate: CarbonImmutable::now());
    $result = $payoutService->createWeeklyPayoutBatch($input);
    $batch = $result->batch;
    $payout = SellerPayout::where('payout_batch_id', $batch->id)->firstOrFail();

    $settlement->refresh();
    expect($settlement->status)->toBe(SettlementStatus::InPayout);

    $payoutService->markPayoutFailed($payout->id, 'Bank account blocked by beneficiary bank');

    $payout->refresh();
    $settlement->refresh();

    expect($payout->status)->toBe(PayoutStatus::Failed)
        ->and($payout->failure_reason)->toBe('Bank account blocked by beneficiary bank')
        ->and($settlement->status)->toBe(SettlementStatus::Eligible)
        ->and($settlement->payout_id)->toBeNull();
});
