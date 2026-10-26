<?php

namespace App\Fees\Services;

use App\Fees\Contracts\OrderItemFeeSnapshotLookupInterface;
use App\Fees\DTOs\FeeBreakdownDto;
use App\Fees\DTOs\SellerOrderFeeSummaryDto;
use App\Fees\Models\OrderItemFeeSnapshot;
use App\Shared\Enums\ShippingZone;
use App\Shared\ValueObjects\Money;

class OrderItemFeeSnapshotLookupService implements OrderItemFeeSnapshotLookupInterface
{
    /**
     * @return array<int, FeeBreakdownDto>
     */
    public function getSnapshotsForSellerOrder(string $sellerOrderId, int $sellerProfileId): array
    {
        $snapshots = OrderItemFeeSnapshot::where('seller_order_id', $sellerOrderId)->get();

        if ($snapshots->isEmpty()) {
            return [];
        }

        // Security check: verify that this seller order belongs to the given seller profile
        $first = $snapshots->first();
        if (($first->snapshot_data['seller_profile_id'] ?? null) !== $sellerProfileId) {
            return [];
        }

        return $snapshots->map(fn (OrderItemFeeSnapshot $snap) => $this->mapSnapshotToDto($snap))->all();
    }

    /**
     * @return array<int, FeeBreakdownDto>
     */
    public function getInternalSnapshotsForSellerOrder(string $sellerOrderId): array
    {
        $snapshots = OrderItemFeeSnapshot::where('seller_order_id', $sellerOrderId)->get();

        return $snapshots->map(fn (OrderItemFeeSnapshot $snap) => $this->mapSnapshotToDto($snap))->all();
    }

    public function getSellerOrderFeeSummary(string $sellerOrderId, int $sellerProfileId): ?SellerOrderFeeSummaryDto
    {
        $dtos = $this->getSnapshotsForSellerOrder($sellerOrderId, $sellerProfileId);

        if (empty($dtos)) {
            return null;
        }

        $grossPaise = 0;
        $commissionPaise = 0;
        $closingPaise = 0;
        $shippingPaise = 0;
        $collectionPaise = 0;
        $gstOnFeesPaise = 0;
        $tcsPaise = 0;
        $tdsPaise = 0;
        $totalDeductionsPaise = 0;
        $netPayoutPaise = 0;
        $creditsPaise = 0;

        foreach ($dtos as $dto) {
            $grossPaise += $dto->lineTotal->getPaise();
            $commissionPaise += $dto->commission->getPaise();
            $closingPaise += $dto->closingFee->getPaise();
            $shippingPaise += $dto->shippingFee->getPaise();
            $collectionPaise += $dto->collectionFee->getPaise();
            $gstOnFeesPaise += $dto->gstOnFees->getPaise();
            $tcsPaise += $dto->tcs->getPaise();
            $tdsPaise += $dto->tds->getPaise();
            $totalDeductionsPaise += $dto->totalDeductions->getPaise();
            $netPayoutPaise += $dto->netPayout->getPaise();
            $creditsPaise += $dto->claimableCredits->getPaise();
        }

        $totalMarketplaceFeesPaise = $commissionPaise + $closingPaise + $shippingPaise + $collectionPaise;

        return new SellerOrderFeeSummaryDto(
            sellerOrderId: $sellerOrderId,
            sellerProfileId: $sellerProfileId,
            itemBreakdowns: $dtos,
            grossSellingPrice: Money::fromPaise($grossPaise),
            commissionTotal: Money::fromPaise($commissionPaise),
            closingFeeTotal: Money::fromPaise($closingPaise),
            shippingFeeTotal: Money::fromPaise($shippingPaise),
            collectionFeeTotal: Money::fromPaise($collectionPaise),
            totalMarketplaceFees: Money::fromPaise($totalMarketplaceFeesPaise),
            gstOnFeesTotal: Money::fromPaise($gstOnFeesPaise),
            tcsTotal: Money::fromPaise($tcsPaise),
            tdsTotal: Money::fromPaise($tdsPaise),
            totalDeductions: Money::fromPaise($totalDeductionsPaise),
            netPayout: Money::fromPaise($netPayoutPaise),
            claimableCredits: Money::fromPaise($creditsPaise),
        );
    }

    private function mapSnapshotToDto(OrderItemFeeSnapshot $snap): FeeBreakdownDto
    {
        $zoneStr = $snap->snapshot_data['zone'] ?? 'national';
        $zone = ShippingZone::tryFrom($zoneStr) ?? ShippingZone::National;

        return new FeeBreakdownDto(
            sellingPrice: Money::fromPaise($snap->selling_price),
            qty: $snap->qty,
            lineTotal: Money::fromPaise($snap->selling_price * $snap->qty),
            taxableValue: Money::fromPaise($snap->snapshot_data['taxable_value_paise'] ?? 0),
            commission: Money::fromPaise($snap->commission_amount),
            closingFee: Money::fromPaise($snap->closing_fee_amount),
            shippingFee: Money::fromPaise($snap->shipping_fee_amount),
            collectionFee: Money::fromPaise($snap->collection_fee_amount),
            totalMarketplaceFees: Money::fromPaise(
                $snap->commission_amount + $snap->closing_fee_amount + $snap->shipping_fee_amount + $snap->collection_fee_amount
            ),
            gstOnFees: Money::fromPaise($snap->gst_on_fees_amount),
            tcs: Money::fromPaise($snap->tcs_amount),
            tds: Money::fromPaise($snap->tds_amount),
            totalDeductions: Money::fromPaise($snap->total_deductions),
            netPayout: Money::fromPaise($snap->net_payout),
            claimableCredits: Money::fromPaise($snap->claimable_credits),
            zone: $zone,
            scheduleId: (int) $snap->fee_schedule_id,
            scheduleVersion: (int) $snap->schedule_version,
            commissionRateBps: (int) ($snap->snapshot_data['commission_rate_bps'] ?? 0),
            gstRateOnFees: (float) ($snap->snapshot_data['gst_rate_on_fees'] ?? 18.0),
            tcsRate: (float) ($snap->snapshot_data['tcs_rate'] ?? 0.5),
            tdsRate: (float) ($snap->snapshot_data['tds_rate'] ?? 0.1),
            orderItemId: (int) $snap->order_item_id,
        );
    }
}
