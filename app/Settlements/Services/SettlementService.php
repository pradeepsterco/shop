<?php

namespace App\Settlements\Services;

use App\Fees\Contracts\OrderItemFeeSnapshotLookupInterface;
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
use Illuminate\Support\Facades\DB;

class SettlementService implements SettlementServiceInterface
{
    public function __construct(
        protected SellerOrderStatusLookupInterface $orderStatusLookup,
        protected OrderItemFeeSnapshotLookupInterface $feeSnapshotLookup,
        protected SellerLedgerServiceInterface $ledgerService,
    ) {}

    public function createSettlementForSellerOrder(string $sellerOrderId): ?SellerOrderSettlement
    {
        $existing = SellerOrderSettlement::where('seller_order_id', $sellerOrderId)->first();
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
        $timezone = (string) config('settlements.timezone', 'Asia/Kolkata');

        if ($statusDto->status === 'cancelled') {
            $status = SettlementStatus::Void;
            $holdUntil = null;
        } elseif ($statusDto->status === 'delivered') {
            $status = SettlementStatus::OnHold;
            $deliveredBase = $statusDto->deliveredAt
                ? CarbonImmutable::parse($statusDto->deliveredAt, $timezone)
                : CarbonImmutable::now($timezone);
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
        $settlement = SellerOrderSettlement::where('seller_order_id', $sellerOrderId)->first();
        if (! $settlement) {
            return null;
        }

        if ($settlement->status === SettlementStatus::Void
            || $settlement->status === SettlementStatus::InPayout
            || $settlement->status === SettlementStatus::Paid) {
            return $settlement;
        }

        if ($settlement->status === SettlementStatus::Pending) {
            $timezone = (string) config('settlements.timezone', 'Asia/Kolkata');
            $deliveredBase = $deliveredAt ?? CarbonImmutable::now($timezone);
            $holdUntil = $deliveredBase->addDays($settlement->return_window_days);

            $settlement->update([
                'status' => SettlementStatus::OnHold,
                'hold_until' => $holdUntil,
            ]);
        }

        return $settlement->fresh();
    }

    public function voidSettlement(string $sellerOrderId): ?SellerOrderSettlement
    {
        $settlement = SellerOrderSettlement::where('seller_order_id', $sellerOrderId)->first();
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

    public function releaseMaturedSettlements(): int
    {
        $timezone = (string) config('settlements.timezone', 'Asia/Kolkata');
        $now = CarbonImmutable::now($timezone);

        $matured = SellerOrderSettlement::where('status', SettlementStatus::OnHold)
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
        return SellerOrderSettlement::where('seller_order_id', $sellerOrderId)->first();
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
