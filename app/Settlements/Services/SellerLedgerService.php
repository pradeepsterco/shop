<?php

namespace App\Settlements\Services;

use App\Fees\DTOs\FeeBreakdownDto;
use App\Settlements\Contracts\SellerLedgerServiceInterface;
use App\Settlements\DTOs\SellerBalanceDto;
use App\Settlements\Enums\LedgerEntryType;
use App\Settlements\Enums\PayoutStatus;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Settlements\Models\SellerPayout;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SellerLedgerService implements SellerLedgerServiceInterface
{
    /**
     * @param  array<int, FeeBreakdownDto>  $feeSnapshots
     */
    public function recordEntriesForSettlement(SellerOrderSettlement $settlement, array $feeSnapshots): void
    {
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($settlement, $feeSnapshots, $now) {
            foreach ($feeSnapshots as $snapshot) {
                $orderItemId = $snapshot->orderItemId;
                $lineTotalPaise = $snapshot->lineTotal->getPaise();
                $commissionPaise = $snapshot->commission->getPaise();
                $closingFeePaise = $snapshot->closingFee->getPaise();
                $shippingFeePaise = $snapshot->shippingFee->getPaise();
                $collectionFeePaise = $snapshot->collectionFee->getPaise();
                $gstOnFeesPaise = $snapshot->gstOnFees->getPaise();
                $tcsPaise = $snapshot->tcs->getPaise();
                $tdsPaise = $snapshot->tds->getPaise();

                // 1. Sale (Credit)
                $this->createEntryIfNotExists([
                    'seller_profile_id' => $settlement->seller_profile_id,
                    'seller_order_id' => $settlement->seller_order_id,
                    'order_item_id' => $orderItemId,
                    'payout_id' => null,
                    'type' => LedgerEntryType::Sale,
                    'amount' => $lineTotalPaise,
                    'description' => "Item sale (Qty: {$snapshot->qty})",
                    'source_key' => "settlement:{$settlement->seller_order_id}:sale:{$orderItemId}",
                    'created_at' => $now,
                ]);

                // 2. Commission (Debit)
                if ($commissionPaise > 0) {
                    $this->createEntryIfNotExists([
                        'seller_profile_id' => $settlement->seller_profile_id,
                        'seller_order_id' => $settlement->seller_order_id,
                        'order_item_id' => $orderItemId,
                        'payout_id' => null,
                        'type' => LedgerEntryType::Commission,
                        'amount' => -$commissionPaise,
                        'description' => 'Marketplace commission fee',
                        'source_key' => "settlement:{$settlement->seller_order_id}:commission:{$orderItemId}",
                        'created_at' => $now,
                    ]);
                }

                // 3. Closing fee (Debit)
                if ($closingFeePaise > 0) {
                    $this->createEntryIfNotExists([
                        'seller_profile_id' => $settlement->seller_profile_id,
                        'seller_order_id' => $settlement->seller_order_id,
                        'order_item_id' => $orderItemId,
                        'payout_id' => null,
                        'type' => LedgerEntryType::ClosingFee,
                        'amount' => -$closingFeePaise,
                        'description' => 'Fixed closing fee',
                        'source_key' => "settlement:{$settlement->seller_order_id}:closing_fee:{$orderItemId}",
                        'created_at' => $now,
                    ]);
                }

                // 4. Shipping fee (Debit)
                if ($shippingFeePaise > 0) {
                    $this->createEntryIfNotExists([
                        'seller_profile_id' => $settlement->seller_profile_id,
                        'seller_order_id' => $settlement->seller_order_id,
                        'order_item_id' => $orderItemId,
                        'payout_id' => null,
                        'type' => LedgerEntryType::ShippingFee,
                        'amount' => -$shippingFeePaise,
                        'description' => 'Allocated shipping & logistics fee',
                        'source_key' => "settlement:{$settlement->seller_order_id}:shipping_fee:{$orderItemId}",
                        'created_at' => $now,
                    ]);
                }

                // 5. Collection fee (Debit)
                if ($collectionFeePaise > 0) {
                    $this->createEntryIfNotExists([
                        'seller_profile_id' => $settlement->seller_profile_id,
                        'seller_order_id' => $settlement->seller_order_id,
                        'order_item_id' => $orderItemId,
                        'payout_id' => null,
                        'type' => LedgerEntryType::CollectionFee,
                        'amount' => -$collectionFeePaise,
                        'description' => 'Payment collection fee',
                        'source_key' => "settlement:{$settlement->seller_order_id}:collection_fee:{$orderItemId}",
                        'created_at' => $now,
                    ]);
                }

                // 6. GST on fees (Debit)
                if ($gstOnFeesPaise > 0) {
                    $this->createEntryIfNotExists([
                        'seller_profile_id' => $settlement->seller_profile_id,
                        'seller_order_id' => $settlement->seller_order_id,
                        'order_item_id' => $orderItemId,
                        'payout_id' => null,
                        'type' => LedgerEntryType::GstOnFees,
                        'amount' => -$gstOnFeesPaise,
                        'description' => 'GST on marketplace fees (18%)',
                        'source_key' => "settlement:{$settlement->seller_order_id}:gst_on_fees:{$orderItemId}",
                        'created_at' => $now,
                    ]);
                }

                // 7. TCS (Debit)
                if ($tcsPaise > 0) {
                    $this->createEntryIfNotExists([
                        'seller_profile_id' => $settlement->seller_profile_id,
                        'seller_order_id' => $settlement->seller_order_id,
                        'order_item_id' => $orderItemId,
                        'payout_id' => null,
                        'type' => LedgerEntryType::Tcs,
                        'amount' => -$tcsPaise,
                        'description' => 'GST TCS (Sec 52)',
                        'source_key' => "settlement:{$settlement->seller_order_id}:tcs:{$orderItemId}",
                        'created_at' => $now,
                    ]);
                }

                // 8. TDS (Debit)
                if ($tdsPaise > 0) {
                    $this->createEntryIfNotExists([
                        'seller_profile_id' => $settlement->seller_profile_id,
                        'seller_order_id' => $settlement->seller_order_id,
                        'order_item_id' => $orderItemId,
                        'payout_id' => null,
                        'type' => LedgerEntryType::Tds,
                        'amount' => -$tdsPaise,
                        'description' => 'Income Tax TDS (Sec 194-O)',
                        'source_key' => "settlement:{$settlement->seller_order_id}:tds:{$orderItemId}",
                        'created_at' => $now,
                    ]);
                }
            }
        });
    }

    public function recordReversalsForSettlement(SellerOrderSettlement $settlement): void
    {
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($settlement, $now) {
            $existingEntries = SellerLedgerEntry::where('seller_order_id', $settlement->seller_order_id)
                ->where('type', '!=', LedgerEntryType::Reversal->value)
                ->get();

            foreach ($existingEntries as $entry) {
                $reversalSourceKey = "reversal:{$entry->source_key}";

                $this->createEntryIfNotExists([
                    'seller_profile_id' => $entry->seller_profile_id,
                    'seller_order_id' => $entry->seller_order_id,
                    'order_item_id' => $entry->order_item_id,
                    'payout_id' => null,
                    'type' => LedgerEntryType::Reversal,
                    'amount' => -$entry->amount,
                    'description' => "Reversal: {$entry->description}",
                    'source_key' => $reversalSourceKey,
                    'created_at' => $now,
                ]);
            }
        });
    }

    public function getSellerBalance(int $sellerId): SellerBalanceDto
    {
        $onHoldPaise = (int) SellerOrderSettlement::where('seller_profile_id', $sellerId)
            ->where('status', SettlementStatus::OnHold->value)
            ->sum('net_payout');

        $eligibleSettlementsPaise = (int) SellerOrderSettlement::where('seller_profile_id', $sellerId)
            ->where('status', SettlementStatus::Eligible->value)
            ->sum('net_payout');

        $inPayoutPaise = (int) SellerPayout::where('seller_profile_id', $sellerId)
            ->whereIn('status', [PayoutStatus::Draft->value, PayoutStatus::Processing->value])
            ->sum('net_amount');

        $lifetimePaidPaise = (int) SellerPayout::where('seller_profile_id', $sellerId)
            ->where('status', PayoutStatus::Paid->value)
            ->sum('net_amount');

        // Check for any negative adjustments or carried negative balance
        // Ledger net of non-settlement adjustments or unassigned adjustments
        $adjustmentsSum = (int) SellerLedgerEntry::where('seller_profile_id', $sellerId)
            ->where('type', LedgerEntryType::Adjustment->value)
            ->sum('amount');

        $carriedNegativePaise = 0;
        if ($adjustmentsSum < 0) {
            $carriedNegativePaise = abs($adjustmentsSum);
        }

        $effectiveEligiblePaise = max(0, $eligibleSettlementsPaise - $carriedNegativePaise);

        return new SellerBalanceDto(
            onHoldPaise: $onHoldPaise,
            eligiblePaise: $effectiveEligiblePaise,
            inPayoutPaise: $inPayoutPaise,
            lifetimePaidPaise: $lifetimePaidPaise,
            carriedNegativePaise: $carriedNegativePaise,
        );
    }

    /**
     * @return Collection<int, SellerLedgerEntry>
     */
    public function getLedgerEntriesForSettlement(string $settlementId): Collection
    {
        $settlement = SellerOrderSettlement::find($settlementId);
        if (! $settlement) {
            return new Collection;
        }

        return SellerLedgerEntry::where('seller_order_id', $settlement->seller_order_id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createEntryIfNotExists(array $data): void
    {
        SellerLedgerEntry::firstOrCreate(
            ['source_key' => $data['source_key']],
            $data
        );
    }
}
