<?php

namespace App\Settlements\Services;

use App\Sellers\Contracts\SellerPayoutLookupInterface;
use App\Sellers\DTOs\SellerPayoutDetailsDto;
use App\Settlements\Contracts\PayoutServiceInterface;
use App\Settlements\Contracts\SellerLedgerServiceInterface;
use App\Settlements\DTOs\PayoutBatchCreateInput;
use App\Settlements\DTOs\PayoutBatchResultDto;
use App\Settlements\Enums\LedgerEntryType;
use App\Settlements\Enums\PayoutStatus;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Events\PayoutBatchCreated;
use App\Settlements\Events\PayoutCompleted;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Settlements\Models\SellerPayout;
use App\Settlements\Models\SellerPayoutBatch;
use App\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class PayoutService implements PayoutServiceInterface
{
    public function __construct(
        protected SellerPayoutLookupInterface $sellerPayoutLookup,
        protected SellerLedgerServiceInterface $ledgerService,
    ) {}

    public function createWeeklyPayoutBatch(PayoutBatchCreateInput $input): PayoutBatchResultDto
    {
        return DB::transaction(function () use ($input) {
            // 1. Enforce single open batch constraint
            $openBatch = SellerPayoutBatch::whereIn('status', ['draft', 'processing'])
                ->lockForUpdate()
                ->first();

            if ($openBatch) {
                throw new RuntimeException("An open payout batch already exists (#{$openBatch->batch_number}). Complete or cancel it before creating a new batch.");
            }

            // 2. Lock and fetch all eligible settlements
            $eligibleSettlements = SellerOrderSettlement::where('status', SettlementStatus::Eligible->value)
                ->lockForUpdate()
                ->get();

            $minPayoutPaise = (int) config('settlements.minimum_payout_amount_paise', 100000);
            $excludedSellers = [];
            $manualReviewSellers = [];

            if ($eligibleSettlements->isEmpty()) {
                return new PayoutBatchResultDto(
                    batch: null,
                    totalPaise: 0,
                    payoutCount: 0,
                    excludedSellers: [],
                    manualReviewSellers: []
                );
            }

            $settlementsBySeller = $eligibleSettlements->groupBy('seller_profile_id');
            $payoutsData = [];

            foreach ($settlementsBySeller as $sellerProfileId => $settlements) {
                $sellerId = (int) $sellerProfileId;
                $details = $this->sellerPayoutLookup->getSellerPayoutDetails($sellerId);

                if (! $details) {
                    $manualReviewSellers[] = [
                        'seller_id' => $sellerId,
                        'business_name' => 'Unknown Seller #'.$sellerId,
                        'reason' => 'Seller details not found',
                    ];

                    continue;
                }

                // Exclude anonymized sellers -> manual review
                if ($details->isAnonymized) {
                    $manualReviewSellers[] = [
                        'seller_id' => $sellerId,
                        'business_name' => $details->storeName,
                        'reason' => 'Seller profile is anonymized or deleted — manual review required',
                    ];

                    continue;
                }

                // Exclude non-approved sellers (pending, suspended)
                if ($details->status !== 'approved') {
                    $excludedSellers[] = [
                        'seller_id' => $sellerId,
                        'business_name' => $details->storeName,
                        'reason' => "Seller status is '{$details->status}' (requires approved status)",
                    ];

                    continue;
                }

                // Exclude sellers with unverified bank changes
                if (! $details->isBankDetailsVerified) {
                    $excludedSellers[] = [
                        'seller_id' => $sellerId,
                        'business_name' => $details->storeName,
                        'reason' => 'held: bank details pending verification',
                    ];

                    continue;
                }

                $grossPaise = (int) $settlements->sum('net_payout');
                $balance = $this->ledgerService->getSellerBalance($sellerId);
                $carriedDeduction = min($grossPaise, $balance->carriedNegativePaise);
                $netPaise = $grossPaise - $carriedDeduction;

                // Check minimum payout threshold
                if ($netPaise < $minPayoutPaise) {
                    $minMoney = Money::fromPaise($minPayoutPaise)->format();
                    $excludedSellers[] = [
                        'seller_id' => $sellerId,
                        'business_name' => $details->storeName,
                        'reason' => "Net payable amount is below minimum threshold ({$minMoney})",
                    ];

                    continue;
                }

                $payoutsData[] = [
                    'seller_id' => $sellerId,
                    'details' => $details,
                    'settlements' => $settlements,
                    'gross_paise' => $grossPaise,
                    'carried_deduction' => $carriedDeduction,
                    'net_paise' => $netPaise,
                ];
            }

            if (empty($payoutsData)) {
                return new PayoutBatchResultDto(
                    batch: null,
                    totalPaise: 0,
                    payoutCount: 0,
                    excludedSellers: $excludedSellers,
                    manualReviewSellers: $manualReviewSellers
                );
            }

            $tz = (string) config('app.display_timezone', 'Asia/Kolkata');
            $localScheduledDate = $input->scheduledPayoutDate->setTimezone($tz);
            $batchNumber = 'BATCH-'.$localScheduledDate->format('Ymd').'-'.Str::upper(Str::random(4));
            $totalBatchAmount = array_sum(array_column($payoutsData, 'net_paise'));

            /** @var SellerPayoutBatch $batch */
            $batch = SellerPayoutBatch::create([
                'batch_number' => $batchNumber,
                'cutoff_date' => $localScheduledDate->toDateString(),
                'status' => 'draft',
                'total_sellers_count' => count($payoutsData),
                'total_amount' => $totalBatchAmount,
                'created_by_admin_id' => $input->createdByAdminId,
            ]);

            foreach ($payoutsData as $item) {
                /** @var SellerPayoutDetailsDto $details */
                $details = $item['details'];

                /** @var SellerPayout $payout */
                $payout = SellerPayout::create([
                    'payout_batch_id' => $batch->id,
                    'seller_profile_id' => $item['seller_id'],
                    'gross_settlement_amount' => $item['gross_paise'],
                    'carried_balance_deduction' => $item['carried_deduction'],
                    'net_amount' => $item['net_paise'],
                    'status' => PayoutStatus::Draft,
                    'bank_account_holder' => $details->bankAccountHolder,
                    'bank_account_number_masked' => $details->maskedAccountNumber,
                    'bank_ifsc' => $details->ifsc,
                    'bank_fingerprint' => $details->bankFingerprint,
                ]);

                // Assign settlements to this payout and update status to in_payout
                SellerOrderSettlement::whereIn('id', $item['settlements']->pluck('id'))
                    ->update([
                        'status' => SettlementStatus::InPayout->value,
                        'payout_id' => $payout->id,
                    ]);
            }

            PayoutBatchCreated::dispatch($batch->id, $batch->total_amount, $batch->total_sellers_count);

            return new PayoutBatchResultDto(
                batch: $batch,
                totalPaise: $totalBatchAmount,
                payoutCount: count($payoutsData),
                excludedSellers: $excludedSellers,
                manualReviewSellers: $manualReviewSellers
            );
        });
    }

    public function generateBankCsv(string $batchId): string
    {
        $batch = SellerPayoutBatch::with('payouts')->findOrFail($batchId);

        $rows = [];
        $rows[] = ['Beneficiary Name', 'Account Number', 'IFSC Code', 'Amount (INR)', 'Payment Ref', 'Payout ID', 'Date'];

        foreach ($batch->payouts as $payout) {
            $currentDetails = $this->sellerPayoutLookup->getSellerPayoutDetails($payout->seller_profile_id);
            $fullAccountNumber = $this->sellerPayoutLookup->getFullAccountNumberForExport($payout->seller_profile_id);

            if (! $currentDetails || ! $fullAccountNumber) {
                throw new RuntimeException("Bank details missing for seller #{$payout->seller_profile_id}.");
            }

            // Fingerprint check: Current details MUST match the fingerprint stored at batch creation
            if ($currentDetails->bankFingerprint !== $payout->bank_fingerprint) {
                throw new RuntimeException("Security verification failed: Bank details for seller #{$payout->seller_profile_id} ({$currentDetails->storeName}) have been altered since batch creation. Export aborted.");
            }

            $amountInr = number_format($payout->net_amount / 100, 2, '.', '');
            $paymentRef = "PAY-{$batch->batch_number}-{$payout->seller_profile_id}";

            $rows[] = [
                $payout->bank_account_holder,
                $fullAccountNumber,
                $payout->bank_ifsc,
                $amountInr,
                $paymentRef,
                $payout->id,
                format_datetime($payout->created_at),
            ];
        }

        // Advance batch to processing if draft
        if ($batch->status === 'draft') {
            $batch->update(['status' => 'processing']);
            SellerPayout::where('payout_batch_id', $batchId)
                ->where('status', PayoutStatus::Draft->value)
                ->update(['status' => PayoutStatus::Processing->value]);
        }

        $handle = fopen('php://temp', 'r+');
        if (! $handle) {
            throw new RuntimeException('Unable to open memory stream for CSV generation.');
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv ?: '';
    }

    public function markPayoutPaid(string $payoutId, string $utr): void
    {
        DB::transaction(function () use ($payoutId, $utr) {
            /** @var SellerPayout $payout */
            $payout = SellerPayout::lockForUpdate()->findOrFail($payoutId);

            if ($payout->status === PayoutStatus::Paid) {
                return;
            }

            $now = CarbonImmutable::now();

            $payout->update([
                'status' => PayoutStatus::Paid,
                'utr' => $utr,
                'paid_at' => $now,
            ]);

            // Update associated settlements
            SellerOrderSettlement::where('payout_id', $payoutId)
                ->update([
                    'status' => SettlementStatus::Paid->value,
                    'paid_at' => $now,
                ]);

            // Record payout debit entry in seller ledger
            SellerLedgerEntry::firstOrCreate(
                ['source_key' => "payout:{$payout->id}"],
                [
                    'seller_profile_id' => $payout->seller_profile_id,
                    'seller_order_id' => null,
                    'order_item_id' => null,
                    'payout_id' => $payout->id,
                    'type' => LedgerEntryType::Payout,
                    'amount' => -$payout->net_amount,
                    'description' => "Bank payout disbursement (UTR: {$utr})",
                    'created_at' => $now,
                ]
            );

            // If all payouts in batch are completed, mark batch completed
            $remaining = SellerPayout::where('payout_batch_id', $payout->payout_batch_id)
                ->whereIn('status', [PayoutStatus::Draft->value, PayoutStatus::Processing->value])
                ->count();

            if ($remaining === 0) {
                SellerPayoutBatch::where('id', $payout->payout_batch_id)
                    ->update(['status' => 'completed']);
            }

            PayoutCompleted::dispatch(
                $payout->id,
                $payout->payout_batch_id,
                $payout->seller_profile_id,
                $payout->net_amount,
                $utr
            );
        });
    }

    public function markPayoutFailed(string $payoutId, string $failureReason): void
    {
        DB::transaction(function () use ($payoutId, $failureReason) {
            /** @var SellerPayout $payout */
            $payout = SellerPayout::lockForUpdate()->findOrFail($payoutId);

            if ($payout->status === PayoutStatus::Failed || $payout->status === PayoutStatus::Paid) {
                return;
            }

            $now = CarbonImmutable::now();

            $payout->update([
                'status' => PayoutStatus::Failed,
                'failed_at' => $now,
                'failure_reason' => $failureReason,
            ]);

            // Revert settlements back to eligible
            SellerOrderSettlement::where('payout_id', $payoutId)
                ->update([
                    'status' => SettlementStatus::Eligible->value,
                    'payout_id' => null,
                ]);

            // Check if batch is completed
            $remaining = SellerPayout::where('payout_batch_id', $payout->payout_batch_id)
                ->whereIn('status', [PayoutStatus::Draft->value, PayoutStatus::Processing->value])
                ->count();

            if ($remaining === 0) {
                SellerPayoutBatch::where('id', $payout->payout_batch_id)
                    ->update(['status' => 'completed']);
            }
        });
    }

    public function cancelBatch(string $batchId): void
    {
        DB::transaction(function () use ($batchId) {
            /** @var SellerPayoutBatch $batch */
            $batch = SellerPayoutBatch::with('payouts')->lockForUpdate()->findOrFail($batchId);

            if ($batch->status === 'completed') {
                throw new BadRequestException('Cannot cancel an already completed batch.');
            }

            foreach ($batch->payouts as $payout) {
                if ($payout->status !== PayoutStatus::Paid) {
                    $payout->update(['status' => PayoutStatus::Failed, 'failure_reason' => 'Batch cancelled by admin']);

                    // Revert settlements
                    SellerOrderSettlement::where('payout_id', $payout->id)
                        ->update([
                            'status' => SettlementStatus::Eligible->value,
                            'payout_id' => null,
                        ]);
                }
            }

            $batch->update(['status' => 'cancelled']);
        });
    }
}
