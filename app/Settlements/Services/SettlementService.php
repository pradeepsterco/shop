<?php

namespace App\Settlements\Services;

use App\Fees\Contracts\OrderItemFeeSnapshotLookupInterface;
use App\Orders\Contracts\SellerOrderReturnWindowLookupInterface;
use App\Orders\Contracts\SellerOrderStatusLookupInterface;
use App\Settlements\Contracts\SellerLedgerServiceInterface;
use App\Settlements\Contracts\SettlementServiceInterface;
use App\Settlements\DTOs\SettlementSummaryDto;
use App\Settlements\Enums\LedgerEntryType;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Events\SettlementCreated;
use App\Settlements\Events\SettlementEligible;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class SettlementService implements SettlementServiceInterface
{
    public function __construct(
        protected SellerOrderStatusLookupInterface $orderStatusLookup,
        protected OrderItemFeeSnapshotLookupInterface $feeSnapshotLookup,
        protected SellerLedgerServiceInterface $ledgerService,
        protected SellerOrderReturnWindowLookupInterface $returnWindowLookup,
    ) {}

    public function createSettlementForSellerOrder(string $sellerOrderId): ?SellerOrderSettlement
    {
        $existing = SellerOrderSettlement::forSellerOrder($sellerOrderId)->first();
        if ($existing) {
            return $existing;
        }

        $feeSnapshots = $this->feeSnapshotLookup->getInternalSnapshotsForSellerOrder($sellerOrderId);
        if (empty($feeSnapshots)) {
            return null;
        }

        $statusDto = $this->orderStatusLookup->getSellerOrderStatus($sellerOrderId);
        if (! $statusDto) {
            return null;
        }

        $grossPaise = 0;
        $totalDeductionsPaise = 0;
        $netPayoutPaise = 0;
        $claimableCreditsPaise = 0;

        foreach ($feeSnapshots as $snap) {
            $grossPaise += $snap->lineTotal->getPaise();
            $totalDeductionsPaise += $snap->totalDeductions->getPaise();
            $netPayoutPaise += $snap->netPayout->getPaise();
            $claimableCreditsPaise += $snap->claimableCredits->getPaise();
        }

        $returnWindowDays = (int) config('settlements.return_window_days', 7);

        if ($statusDto->status === 'cancelled') {
            $status = SettlementStatus::Void;
            $holdUntil = null;
        } elseif ($statusDto->status === 'delivered') {
            $status = SettlementStatus::OnHold;
            $returnWindowDays = $this->returnWindowLookup->returnWindowDays($sellerOrderId);
            // App time (UTC): hold_until is stored as wall-clock, so an IST-zoned value would land 5.5 hours late
            $deliveredBase = $statusDto->deliveredAt
                ? CarbonImmutable::instance($statusDto->deliveredAt)->setTimezone(config('app.timezone'))
                : CarbonImmutable::now();
            $holdUntil = $deliveredBase->addDays($returnWindowDays);
        } else {
            $status = SettlementStatus::Pending;
            $holdUntil = null;
        }

        /** @var SellerOrderSettlement $settlement */
        $settlement = DB::transaction(function () use (
            $sellerOrderId,
            $statusDto,
            $grossPaise,
            $totalDeductionsPaise,
            $netPayoutPaise,
            $claimableCreditsPaise,
            $status,
            $returnWindowDays,
            $holdUntil,
            $feeSnapshots
        ) {
            $created = SellerOrderSettlement::create([
                'seller_order_id' => $sellerOrderId,
                'order_id' => $statusDto->orderId ?? '',
                'seller_profile_id' => $statusDto->sellerProfileId,
                'gross_amount' => $grossPaise,
                'total_deductions' => $totalDeductionsPaise,
                'net_payout' => $netPayoutPaise,
                'claimable_credits' => $claimableCreditsPaise,
                'status' => $status,
                'return_window_days' => $returnWindowDays,
                'hold_until' => $holdUntil,
            ]);

            $this->ledgerService->recordEntriesForSettlement($created, $feeSnapshots);

            if ($status === SettlementStatus::Void) {
                $this->ledgerService->recordReversalsForSettlement($created);
            }

            return $created;
        });

        SettlementCreated::dispatch(
            $settlement->id,
            $settlement->seller_order_id,
            $settlement->seller_profile_id,
            $settlement->net_payout
        );

        return $settlement;
    }

    public function markSettlementOnHold(string $sellerOrderId, ?CarbonImmutable $deliveredAt = null): ?SellerOrderSettlement
    {
        $settlement = SellerOrderSettlement::forSellerOrder($sellerOrderId)->first();
        if (! $settlement) {
            return null;
        }

        if ($settlement->status === SettlementStatus::Void
            || $settlement->status === SettlementStatus::InPayout
            || $settlement->status === SettlementStatus::Paid) {
            return $settlement;
        }

        if ($settlement->status === SettlementStatus::Pending) {
            // App time, like the delivered path: an IST-zoned instant would be stored as IST wall-clock (+5:30)
            $deliveredBase = $deliveredAt ?? CarbonImmutable::now();
            // Hold for the longest return window among the package's items (per vertical); the window used is stored
            $returnWindowDays = $this->returnWindowLookup->returnWindowDays($sellerOrderId);

            $settlement->update([
                'status' => SettlementStatus::OnHold,
                'return_window_days' => $returnWindowDays,
                'hold_until' => $deliveredBase->addDays($returnWindowDays),
            ]);
        }

        return $settlement->fresh();
    }

    public function voidSettlement(string $sellerOrderId): ?SellerOrderSettlement
    {
        $settlement = SellerOrderSettlement::forSellerOrder($sellerOrderId)->first();
        if (! $settlement) {
            return null;
        }

        if ($settlement->status === SettlementStatus::Void) {
            return $settlement;
        }

        DB::transaction(function () use ($settlement) {
            $settlement->update([
                'status' => SettlementStatus::Void,
                'hold_until' => null,
            ]);

            $this->ledgerService->recordReversalsForSettlement($settlement);
        });

        return $settlement->fresh();
    }

    public function processRtoSettlement(string $sellerOrderId): ?SellerOrderSettlement
    {
        $settlement = SellerOrderSettlement::forSellerOrder($sellerOrderId)->first();
        if (! $settlement) {
            return null;
        }

        DB::transaction(function () use ($settlement) {
            // Record RTO reversals (reverse sale & fees, keep forward shipping, debit RTO shipping)
            $this->ledgerService->recordRtoReversalsForSettlement($settlement);

            // Compute total shipping debited (forward + RTO)
            $sumAmount = (int) SellerLedgerEntry::where('seller_order_id', $settlement->seller_order_id)
                ->where('type', LedgerEntryType::ShippingFee->value)
                ->sum('amount');
            $totalShippingDebit = abs($sumAmount);

            // RTO settlement must not end as Void with uncollected charges (requirement #1)
            // It becomes Eligible with net negative payout = -$totalShippingDebit
            $settlement->update([
                'status' => SettlementStatus::Eligible,
                'gross_amount' => 0,
                'total_deductions' => $totalShippingDebit,
                'net_payout' => -$totalShippingDebit,
                'hold_until' => null,
            ]);
        });

        return $settlement->fresh();
    }

    public function confirmLostShipmentSettlement(string $sellerOrderId, int $adminId, ?string $notes = null): ?SellerOrderSettlement
    {
        $settlement = SellerOrderSettlement::forSellerOrder($sellerOrderId)->first();
        if (! $settlement) {
            return null;
        }

        // Platform bears the loss, so the seller is settled exactly as if the order was delivered now
        return DB::transaction(function () use ($settlement, $sellerOrderId) {
            $settlement->update(['is_partial_delivery_hold' => false]);

            return $this->markSettlementOnHold($sellerOrderId);
        });
    }

    public function releasePartialDeliveryHold(string $sellerOrderId, array $undeliveredOrderItemIds = []): ?SellerOrderSettlement
    {
        $settlement = SellerOrderSettlement::forSellerOrder($sellerOrderId)->first();
        if (! $settlement) {
            return null;
        }

        // Admin has confirmed the delivered items: pay only for those, clear the hold, start the return window
        return DB::transaction(function () use ($settlement, $sellerOrderId, $undeliveredOrderItemIds) {
            $reversed = $this->ledgerService->recordPartialDeliveryReversals($settlement, $undeliveredOrderItemIds);

            $gross = $settlement->gross_amount - $reversed['sale'];
            $net = $settlement->net_payout + $reversed['net'];

            $settlement->update([
                'gross_amount' => $gross,
                'net_payout' => $net,
                'total_deductions' => $gross - $net,
                'is_partial_delivery_hold' => false,
            ]);

            return $this->markSettlementOnHold($sellerOrderId);
        });
    }

    public function startReshipSettlement(string $sellerOrderId, string $reshipShipmentId, array $lines): SellerOrderSettlement
    {
        return DB::transaction(function () use ($sellerOrderId, $reshipShipmentId, $lines) {
            /** @var SellerOrderSettlement|null $order */
            $order = SellerOrderSettlement::forSellerOrder($sellerOrderId)->first();
            if (! $order) {
                throw new DomainException("Seller order {$sellerOrderId} has no settlement to reship against.");
            }

            /** @var SellerOrderSettlement $reship */
            $reship = SellerOrderSettlement::firstOrCreate(
                ['seller_order_id' => $sellerOrderId, 'kind' => SellerOrderSettlement::KIND_RESHIP, 'reference_id' => $reshipShipmentId],
                [
                    'order_id' => $order->order_id,
                    'seller_profile_id' => $order->seller_profile_id,
                    'status' => SettlementStatus::Pending,
                    'gross_amount' => 0,
                    'total_deductions' => 0,
                    'net_payout' => 0,
                    'claimable_credits' => 0,
                    // Reshipped units are held for the window saved when the package was first delivered
                    'return_window_days' => $order->return_window_days,
                ]
            );

            $charge = $this->ledgerService->recordReshipShippingCharge(
                $reship,
                $lines,
                (float) config('returns.reship_shipping_multiplier', 1.0),
            );

            $reship->update(['total_deductions' => $charge, 'net_payout' => -$charge]);

            return $reship->fresh();
        });
    }

    public function completeReshipSettlement(string $reshipShipmentId, array $lines, CarbonImmutable $deliveredAt): ?SellerOrderSettlement
    {
        return DB::transaction(function () use ($reshipShipmentId, $lines, $deliveredAt) {
            /** @var SellerOrderSettlement|null $reship */
            $reship = SellerOrderSettlement::forReship($reshipShipmentId)->lockForUpdate()->first();
            if (! $reship || $reship->status !== SettlementStatus::Pending) {
                return $reship;
            }

            $credits = $this->ledgerService->recordReshipCredits($reship, $lines);
            $net = $reship->net_payout + $credits['net'];

            $reship->update([
                'gross_amount' => $credits['sale'],
                'net_payout' => $net,
                'total_deductions' => $credits['sale'] - $net,
                'status' => SettlementStatus::OnHold,
                'hold_until' => $deliveredAt->addDays($reship->return_window_days),
            ]);

            return $reship->fresh();
        });
    }

    public function returnReshipSettlement(string $reshipShipmentId): ?SellerOrderSettlement
    {
        return DB::transaction(function () use ($reshipShipmentId) {
            /** @var SellerOrderSettlement|null $reship */
            $reship = SellerOrderSettlement::forReship($reshipShipmentId)->lockForUpdate()->first();
            if (! $reship || $reship->status !== SettlementStatus::Pending) {
                return $reship;
            }

            $rto = $this->ledgerService->recordReshipRtoCharge($reship, (float) config('returns.rto_shipping_multiplier', 1.0));

            // Never Void with charges outstanding: eligible and negative, carried into the next payout
            $reship->update([
                'total_deductions' => $reship->total_deductions + $rto,
                'net_payout' => $reship->net_payout - $rto,
                'status' => SettlementStatus::Eligible,
                'hold_until' => null,
            ]);

            return $reship->fresh();
        });
    }

    public function cancelReshipSettlement(string $reshipShipmentId): ?SellerOrderSettlement
    {
        return DB::transaction(function () use ($reshipShipmentId) {
            /** @var SellerOrderSettlement|null $reship */
            $reship = SellerOrderSettlement::forReship($reshipShipmentId)->lockForUpdate()->first();
            if (! $reship || $reship->status !== SettlementStatus::Pending) {
                return $reship;
            }

            $reversed = $this->ledgerService->reverseReshipShippingCharge($reship);

            $reship->update([
                'total_deductions' => max(0, $reship->total_deductions - $reversed),
                'net_payout' => $reship->net_payout + $reversed,
                'status' => SettlementStatus::Void,
            ]);

            return $reship->fresh();
        });
    }

    public function placeReturnHold(string $sellerOrderId): void
    {
        SellerOrderSettlement::forSellerOrder($sellerOrderId)->update(['is_return_hold' => true]);
    }

    public function releaseReturnHold(string $sellerOrderId): void
    {
        SellerOrderSettlement::forSellerOrder($sellerOrderId)->update(['is_return_hold' => false]);
    }

    public function savedReturnWindowDays(string $sellerOrderId): ?int
    {
        /** @var SellerOrderSettlement|null $settlement */
        $settlement = SellerOrderSettlement::forSellerOrder($sellerOrderId)->first();

        // Pending/void settlements were never put on hold at delivery, so no window was saved
        if (! $settlement || in_array($settlement->status, [SettlementStatus::Pending, SettlementStatus::Void], true)) {
            return null;
        }

        return $settlement->return_window_days;
    }

    public function reverseUnitsForReturnClaim(string $sellerOrderId, string $returnRequestId, array $lines): int
    {
        return DB::transaction(function () use ($sellerOrderId, $returnRequestId, $lines) {
            /** @var SellerOrderSettlement|null $order */
            $order = SellerOrderSettlement::forSellerOrder($sellerOrderId)->first();
            if (! $order) {
                throw new DomainException("Seller order {$sellerOrderId} has no settlement to reverse against.");
            }

            /** @var SellerOrderSettlement $claim */
            $claim = SellerOrderSettlement::firstOrCreate(
                ['seller_order_id' => $sellerOrderId, 'kind' => SellerOrderSettlement::KIND_RETURN, 'reference_id' => $returnRequestId],
                [
                    'order_id' => $order->order_id,
                    'seller_profile_id' => $order->seller_profile_id,
                    'status' => SettlementStatus::Eligible,
                    'gross_amount' => 0,
                    'total_deductions' => 0,
                    'net_payout' => 0,
                    'claimable_credits' => 0,
                    'return_window_days' => $order->return_window_days,
                ]
            );

            $debit = $this->ledgerService->recordUnitReversals($claim, $lines, "return:{$returnRequestId}");

            $claim->update(['total_deductions' => $debit, 'net_payout' => -$debit]);

            return $debit;
        });
    }

    public function settleReturn(string $sellerOrderId, string $returnRequestId, array $lines, array $instructions): int
    {
        return DB::transaction(function () use ($sellerOrderId, $returnRequestId, $lines, $instructions) {
            /** @var SellerOrderSettlement|null $order */
            $order = SellerOrderSettlement::forSellerOrder($sellerOrderId)->first();
            if (! $order) {
                throw new DomainException("Seller order {$sellerOrderId} has no settlement to settle the return against.");
            }

            /** @var SellerOrderSettlement $settlement */
            $settlement = SellerOrderSettlement::firstOrCreate(
                ['seller_order_id' => $sellerOrderId, 'kind' => SellerOrderSettlement::KIND_RETURN, 'reference_id' => $returnRequestId],
                [
                    'order_id' => $order->order_id,
                    'seller_profile_id' => $order->seller_profile_id,
                    'status' => SettlementStatus::Eligible,
                    'gross_amount' => 0,
                    'total_deductions' => 0,
                    'net_payout' => 0,
                    'claimable_credits' => 0,
                    'return_window_days' => $order->return_window_days,
                ]
            );

            $unitLines = array_map(fn (array $line) => ['units' => $line['units'], 'line_qty' => $line['line_qty']], $lines);
            $debit = 0;

            if ($instructions['reverse_sale']) {
                [$refunded, $refundable] = $instructions['refund_ratio'];
                foreach ($lines as $orderItemId => $line) {
                    $debit += $this->ledgerService->recordUnitReversals(
                        $settlement,
                        [$orderItemId => $unitLines[$orderItemId]],
                        "return:{$line['return_item_id']}",
                        $instructions['reverse_fees'],
                        $refunded,
                        $refundable,
                    );
                }
            }

            foreach ($instructions['shipping'] as $charge => $multiplier) {
                $debit += $this->ledgerService->recordAllocatedShippingCharge(
                    $settlement,
                    $unitLines,
                    "return:{$returnRequestId}:{$charge}:",
                    (float) $multiplier,
                    ucfirst(str_replace('_', ' ', $charge))." for returned units (multiplier {$multiplier})",
                );
            }

            $settlement->update(['total_deductions' => $debit, 'net_payout' => -$debit, 'status' => SettlementStatus::Eligible]);

            return $debit;
        });
    }

    public function releaseMaturedSettlements(): int
    {
        // hold_until is stored in app time (UTC); an IST-zoned "now" would be bound as IST wall-clock
        // and release settlements 5.5 hours early
        $now = CarbonImmutable::now();

        $matured = SellerOrderSettlement::where('status', SettlementStatus::OnHold)
            ->where('is_partial_delivery_hold', false)
            ->where('is_return_hold', false)
            ->where('hold_until', '<=', $now)
            ->get();

        $count = 0;
        foreach ($matured as $settlement) {
            $settlement->update(['status' => SettlementStatus::Eligible]);
            SettlementEligible::dispatch(
                $settlement->id,
                $settlement->seller_order_id,
                $settlement->seller_profile_id
            );
            $count++;
        }

        // Reconcile pending settlements to detect any missed order deliveries or cancellations
        $pending = SellerOrderSettlement::where('status', SettlementStatus::Pending)->get();
        foreach ($pending as $item) {
            $this->reconcileSettlementState($item);
        }

        return $count;
    }

    public function reconcileSettlementState(SellerOrderSettlement $settlement): bool
    {
        $statusDto = $this->orderStatusLookup->getSellerOrderStatus($settlement->seller_order_id);
        if (! $statusDto) {
            return false;
        }

        if ($statusDto->status === 'cancelled' && $settlement->status !== SettlementStatus::Void) {
            $this->voidSettlement($settlement->seller_order_id);

            return true;
        }

        if ($statusDto->status === 'delivered' && $settlement->status === SettlementStatus::Pending) {
            $timezone = (string) config('settlements.timezone', 'Asia/Kolkata');
            $deliveredAt = $statusDto->deliveredAt ? CarbonImmutable::parse($statusDto->deliveredAt, $timezone) : null;
            $this->markSettlementOnHold($settlement->seller_order_id, $deliveredAt);

            return true;
        }

        return false;
    }

    public function getSettlementBySellerOrderId(string $sellerOrderId): ?SellerOrderSettlement
    {
        return SellerOrderSettlement::forSellerOrder($sellerOrderId)->first();
    }

    public function getSettlementSummary(SellerOrderSettlement $settlement): SettlementSummaryDto
    {
        $entries = SellerLedgerEntry::where('seller_order_id', $settlement->seller_order_id)
            ->where('type', '!=', LedgerEntryType::Reversal->value)
            ->get();

        $commissionPaise = abs((int) $entries->where('type', LedgerEntryType::Commission)->sum('amount'));
        $closingFeePaise = abs((int) $entries->where('type', LedgerEntryType::ClosingFee)->sum('amount'));
        $shippingFeePaise = abs((int) $entries->where('type', LedgerEntryType::ShippingFee)->sum('amount'));
        $paymentFeePaise = abs((int) $entries->where('type', LedgerEntryType::CollectionFee)->sum('amount'));
        $gstOnFeesPaise = abs((int) $entries->where('type', LedgerEntryType::GstOnFees)->sum('amount'));
        $tdsPaise = abs((int) $entries->where('type', LedgerEntryType::Tds)->sum('amount'));
        $tcsPaise = abs((int) $entries->where('type', LedgerEntryType::Tcs)->sum('amount'));

        return new SettlementSummaryDto(
            settlementId: $settlement->id,
            sellerOrderId: $settlement->seller_order_id,
            sellerId: $settlement->seller_profile_id,
            status: $settlement->status,
            grossPaise: $settlement->gross_amount,
            commissionPaise: $commissionPaise,
            closingFeePaise: $closingFeePaise,
            shippingFeePaise: $shippingFeePaise,
            paymentFeePaise: $paymentFeePaise,
            gstOnFeesPaise: $gstOnFeesPaise,
            tdsPaise: $tdsPaise,
            tcsPaise: $tcsPaise,
            netPaise: $settlement->net_payout,
            returnWindowDays: $settlement->return_window_days,
            holdUntil: $settlement->hold_until ? CarbonImmutable::parse($settlement->hold_until) : null,
            releasedAt: $settlement->status === SettlementStatus::Eligible || $settlement->status === SettlementStatus::Paid ? ($settlement->hold_until ? CarbonImmutable::parse($settlement->hold_until) : null) : null,
            payoutId: $settlement->payout_id,
            createdAt: CarbonImmutable::parse($settlement->created_at),
        );
    }
}
