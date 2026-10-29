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

                // 1. Sale (Credit) at the full pre-coupon line total. Correct only because every coupon is
                // platform-funded: the Coupons module refuses to save, evaluate or redeem seller-funded coupons
                // (guarded by tests/Feature/Coupons/SellerFundedCouponGuardTest.php). If seller-funded coupons are
                // ever enabled, the seller's share of the discount must be debited here and in refund reversals.
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

    public function recordRtoReversalsForSettlement(SellerOrderSettlement $settlement): void
    {
        $now = CarbonImmutable::now();

        $rtoShippingSourceKey = "settlement:{$settlement->seller_order_id}:rto_shipping";

        DB::transaction(function () use ($settlement, $now, $rtoShippingSourceKey) {
            // Exclude the RTO debit itself so a re-run neither reverses it nor counts it as forward shipping
            $existingEntries = SellerLedgerEntry::where('seller_order_id', $settlement->seller_order_id)
                ->where('type', '!=', LedgerEntryType::Reversal->value)
                ->where('source_key', '!=', $rtoShippingSourceKey)
                ->get();

            $forwardShippingPaise = 0;

            foreach ($existingEntries as $entry) {
                // If this is the forward shipping fee debit, KEEP IT (do not reverse)
                if ($entry->type === LedgerEntryType::ShippingFee) {
                    $forwardShippingPaise += abs($entry->amount);

                    continue;
                }

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

            // Debit RTO shipping fee: forward shipping fee × multiplier
            $multiplier = (float) config('returns.rto_shipping_multiplier', 1.0);
            $rtoShippingPaise = (int) round($forwardShippingPaise * $multiplier);

            if ($rtoShippingPaise > 0) {
                $this->createEntryIfNotExists([
                    'seller_profile_id' => $settlement->seller_profile_id,
                    'seller_order_id' => $settlement->seller_order_id,
                    'order_item_id' => null,
                    'payout_id' => null,
                    'type' => LedgerEntryType::ShippingFee,
                    'amount' => -$rtoShippingPaise,
                    'description' => "RTO return shipping fee for order {$settlement->seller_order_id} (multiplier: {$multiplier})",
                    'source_key' => $rtoShippingSourceKey,
                    'created_at' => $now,
                ]);
            }
        });
    }

    public function recordPartialDeliveryReversals(SellerOrderSettlement $settlement, array $undeliveredOrderItemIds): array
    {
        $reversed = ['sale' => 0, 'net' => 0];

        if ($undeliveredOrderItemIds === []) {
            return $reversed;
        }

        $now = CarbonImmutable::now();

        DB::transaction(function () use ($settlement, $undeliveredOrderItemIds, $now, &$reversed) {
            $entries = SellerLedgerEntry::where('seller_order_id', $settlement->seller_order_id)
                ->whereIn('order_item_id', $undeliveredOrderItemIds)
                ->whereNotIn('type', [
                    LedgerEntryType::Reversal->value,
                    LedgerEntryType::ShippingFee->value,
                    LedgerEntryType::Payout->value,
                    LedgerEntryType::Adjustment->value,
                ])
                ->get();

            foreach ($entries as $entry) {
                $created = $this->createEntryIfNotExists([
                    'seller_profile_id' => $entry->seller_profile_id,
                    'seller_order_id' => $entry->seller_order_id,
                    'order_item_id' => $entry->order_item_id,
                    'payout_id' => null,
                    'type' => LedgerEntryType::Reversal,
                    'amount' => -$entry->amount,
                    'description' => "Reversal (not delivered): {$entry->description}",
                    'source_key' => "reversal:{$entry->source_key}",
                    'created_at' => $now,
                ]);

                if ($created) {
                    $reversed['net'] -= $entry->amount;
                    if ($entry->type === LedgerEntryType::Sale) {
                        $reversed['sale'] += $entry->amount;
                    }
                }
            }
        });

        return $reversed;
    }

    public function recordReshipShippingCharge(SellerOrderSettlement $reship, array $lines, float $multiplier): int
    {
        return $this->recordAllocatedShippingCharge(
            $reship,
            $lines,
            "reship:{$reship->reference_id}:shipping_fee:",
            $multiplier,
            "Reship shipping for undelivered units (multiplier {$multiplier})",
        );
    }

    public function recordAllocatedShippingCharge(SellerOrderSettlement $settlement, array $lines, string $keyPrefix, float $multiplier, string $description): int
    {
        DB::transaction(function () use ($settlement, $lines, $multiplier, $keyPrefix, $description) {
            foreach ($lines as $orderItemId => $line) {
                // The forward shipping originally allocated to this line, for these units only
                $original = (int) SellerLedgerEntry::where('source_key', "settlement:{$settlement->seller_order_id}:shipping_fee:{$orderItemId}")->value('amount');
                $charge = (int) round(abs(self::scaleToUnits($original, $line['units'], $line['line_qty'])) * $multiplier);

                if ($charge > 0) {
                    $this->createEntryIfNotExists([
                        'seller_profile_id' => $settlement->seller_profile_id,
                        'seller_order_id' => $settlement->seller_order_id,
                        'order_item_id' => $orderItemId,
                        'payout_id' => null,
                        'type' => LedgerEntryType::ShippingFee,
                        'amount' => -$charge,
                        'description' => "{$description} ({$line['units']} unit(s))",
                        'source_key' => $keyPrefix.$orderItemId,
                        'created_at' => CarbonImmutable::now(),
                    ]);
                }
            }
        });

        return (int) -SellerLedgerEntry::where('source_key', 'like', $keyPrefix.'%')->sum('amount');
    }

    public function recordReshipCredits(SellerOrderSettlement $reship, array $lines): array
    {
        $originalPrefix = "settlement:{$reship->seller_order_id}:";

        DB::transaction(function () use ($reship, $lines, $originalPrefix) {
            // Re-post the original sale and fee entries (the fee snapshot taken at order time), never recomputed
            $originals = SellerLedgerEntry::where('seller_order_id', $reship->seller_order_id)
                ->whereIn('order_item_id', array_keys($lines))
                ->where('source_key', 'like', $originalPrefix.'%')
                ->whereNotIn('type', [
                    LedgerEntryType::ShippingFee->value,
                    LedgerEntryType::Reversal->value,
                    LedgerEntryType::Payout->value,
                    LedgerEntryType::Adjustment->value,
                ])
                ->get();

            foreach ($originals as $entry) {
                $line = $lines[(int) $entry->order_item_id];
                $amount = self::scaleToUnits($entry->amount, $line['units'], $line['line_qty']);

                $this->createEntryIfNotExists([
                    'seller_profile_id' => $entry->seller_profile_id,
                    'seller_order_id' => $entry->seller_order_id,
                    'order_item_id' => $entry->order_item_id,
                    'payout_id' => null,
                    'type' => $entry->type,
                    'amount' => $amount,
                    'description' => "Reship delivered: {$entry->description}",
                    'source_key' => "reship:{$reship->reference_id}:".substr($entry->source_key, strlen($originalPrefix)),
                    'created_at' => CarbonImmutable::now(),
                ]);
            }
        });

        $credits = SellerLedgerEntry::where('source_key', 'like', "reship:{$reship->reference_id}:%")
            ->where('type', '!=', LedgerEntryType::ShippingFee->value)
            ->get();

        return [
            'sale' => (int) $credits->where('type', LedgerEntryType::Sale)->sum('amount'),
            'net' => (int) $credits->sum('amount'),
        ];
    }

    public function recordReshipRtoCharge(SellerOrderSettlement $reship, float $multiplier): int
    {
        $forward = (int) -SellerLedgerEntry::where('source_key', 'like', "reship:{$reship->reference_id}:shipping_fee:%")->sum('amount');
        $charge = (int) round($forward * $multiplier);

        if ($charge > 0) {
            $this->createEntryIfNotExists([
                'seller_profile_id' => $reship->seller_profile_id,
                'seller_order_id' => $reship->seller_order_id,
                'order_item_id' => null,
                'payout_id' => null,
                'type' => LedgerEntryType::ShippingFee,
                'amount' => -$charge,
                'description' => "Reship returned to origin: RTO shipping (multiplier {$multiplier})",
                'source_key' => "reship:{$reship->reference_id}:rto_shipping",
                'created_at' => CarbonImmutable::now(),
            ]);
        }

        return (int) -SellerLedgerEntry::where('source_key', "reship:{$reship->reference_id}:rto_shipping")->sum('amount');
    }

    public function reverseReshipShippingCharge(SellerOrderSettlement $reship): int
    {
        $charges = SellerLedgerEntry::where('source_key', 'like', "reship:{$reship->reference_id}:shipping_fee:%")->get();

        DB::transaction(function () use ($charges) {
            foreach ($charges as $entry) {
                $this->createEntryIfNotExists([
                    'seller_profile_id' => $entry->seller_profile_id,
                    'seller_order_id' => $entry->seller_order_id,
                    'order_item_id' => $entry->order_item_id,
                    'payout_id' => null,
                    'type' => LedgerEntryType::Reversal,
                    'amount' => -$entry->amount,
                    'description' => "Reship cancelled: {$entry->description}",
                    'source_key' => "reversal:{$entry->source_key}",
                    'created_at' => CarbonImmutable::now(),
                ]);
            }
        });

        return (int) -$charges->sum('amount');
    }

    public function recordUnitReversals(SellerOrderSettlement $settlement, array $lines, string $keyPrefix, bool $reverseFees = true, int $ratioNumerator = 1, int $ratioDenominator = 1): int
    {
        $originalPrefix = "settlement:{$settlement->seller_order_id}:";
        // Marketplace fees the seller keeps paying when the fault is theirs (sale and its taxes are always reversed)
        $feeTypes = [LedgerEntryType::Commission, LedgerEntryType::ClosingFee, LedgerEntryType::CollectionFee, LedgerEntryType::GstOnFees];

        DB::transaction(function () use ($settlement, $lines, $keyPrefix, $originalPrefix, $reverseFees, $feeTypes, $ratioNumerator, $ratioDenominator) {
            $originals = SellerLedgerEntry::where('seller_order_id', $settlement->seller_order_id)
                ->whereIn('order_item_id', array_keys($lines))
                ->where('source_key', 'like', $originalPrefix.'%')
                ->whereNotIn('type', [
                    LedgerEntryType::ShippingFee->value,
                    LedgerEntryType::Reversal->value,
                    LedgerEntryType::Payout->value,
                    LedgerEntryType::Adjustment->value,
                ])
                ->get();

            foreach ($originals as $entry) {
                if (! $reverseFees && in_array($entry->type, $feeTypes, true)) {
                    continue;
                }

                $line = $lines[(int) $entry->order_item_id];
                $unitsAmount = self::scaleToUnits($entry->amount, $line['units'], $line['line_qty']);
                // A partial refund gives back the same share of the sale (and fees)
                $amount = $ratioDenominator > 0 && $ratioNumerator < $ratioDenominator
                    ? intdiv($unitsAmount * $ratioNumerator, $ratioDenominator)
                    : $unitsAmount;

                $this->createEntryIfNotExists([
                    'seller_profile_id' => $entry->seller_profile_id,
                    'seller_order_id' => $entry->seller_order_id,
                    'order_item_id' => $entry->order_item_id,
                    'payout_id' => null,
                    'type' => LedgerEntryType::Reversal,
                    'amount' => -$amount,
                    'description' => "Reversal ({$line['units']} unit(s) returned or missing): {$entry->description}",
                    'source_key' => "{$keyPrefix}:".substr($entry->source_key, strlen($originalPrefix)),
                    'created_at' => CarbonImmutable::now(),
                ]);
            }
        });

        return (int) -SellerLedgerEntry::where('source_key', 'like', "{$keyPrefix}:%")->sum('amount');
    }

    /**
     * Share of a whole-line amount for some of its units; all units get the full amount (no rounding drift).
     */
    protected static function scaleToUnits(int $lineAmount, int $units, int $lineQty): int
    {
        return $units >= $lineQty || $lineQty <= 0
            ? $lineAmount
            : intdiv($lineAmount * $units, $lineQty);
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
            $carriedNegativePaise += abs($adjustmentsSum);
        }
        if ($eligibleSettlementsPaise < 0) {
            $carriedNegativePaise += abs($eligibleSettlementsPaise);
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
    private function createEntryIfNotExists(array $data): bool
    {
        return SellerLedgerEntry::firstOrCreate(
            ['source_key' => $data['source_key']],
            $data
        )->wasRecentlyCreated;
    }
}
