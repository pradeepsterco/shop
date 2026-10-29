<?php

namespace App\Returns\Services;

use App\Returns\Contracts\ReturnsLookupInterface;
use App\Returns\DTOs\CourierClaimDto;
use App\Returns\DTOs\RtoIntakeReportDto;
use App\Returns\Models\CourierClaim;
use App\Returns\Models\RtoIntakeReport;
use Illuminate\Support\Carbon;

class ReturnsLookupService implements ReturnsLookupInterface
{
    public function getRtoReportForSellerOrder(string $sellerOrderId): ?RtoIntakeReportDto
    {
        $report = RtoIntakeReport::where('seller_order_id', $sellerOrderId)->first();

        return $report ? $this->toReportDto($report) : null;
    }

    public function getRtoReportForShipment(string $shipmentId): ?RtoIntakeReportDto
    {
        $report = RtoIntakeReport::where('shipment_id', $shipmentId)->first();

        return $report ? $this->toReportDto($report) : null;
    }

    /**
     * @return array<int, CourierClaimDto>
     */
    public function getClaimsForShipment(string $shipmentId): array
    {
        return CourierClaim::where('shipment_id', $shipmentId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (CourierClaim $c) => $this->toClaimDto($c))
            ->all();
    }

    /**
     * @return array<int, CourierClaimDto>
     */
    public function getClaimsForSellerOrder(string $sellerOrderId): array
    {
        return CourierClaim::where('seller_order_id', $sellerOrderId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (CourierClaim $c) => $this->toClaimDto($c))
            ->all();
    }

    public function isRtoInspectionDeadlinePassed(?string $rtoDeliveredAt): bool
    {
        if (! $rtoDeliveredAt) {
            return false;
        }

        $days = (int) config('returns.seller_rto_inspection_days', 3);
        $deadline = Carbon::parse($rtoDeliveredAt)->addDays($days);

        return now()->isAfter($deadline);
    }

    protected function toReportDto(RtoIntakeReport $report): RtoIntakeReportDto
    {
        return new RtoIntakeReportDto(
            id: $report->id,
            sellerOrderId: $report->seller_order_id,
            shipmentId: $report->shipment_id,
            sellerProfileId: (int) $report->seller_profile_id,
            conditionStatus: $report->condition_status,
            notes: $report->notes,
            photos: $report->photos,
            reportedAt: $report->reported_at,
            adminDecision: $report->admin_decision,
            adminDecidedAt: $report->admin_decided_at,
            adminId: $report->admin_id ? (int) $report->admin_id : null,
            adminNotes: $report->admin_notes,
        );
    }

    protected function toClaimDto(CourierClaim $claim): CourierClaimDto
    {
        return new CourierClaimDto(
            id: $claim->id,
            claimNumber: $claim->claim_number,
            shipmentId: $claim->shipment_id,
            sellerOrderId: $claim->seller_order_id,
            carrierName: $claim->carrier_name,
            claimType: $claim->claim_type,
            claimAmountPaise: (int) $claim->claim_amount_paise,
            bearer: $claim->bearer,
            status: $claim->status,
            adminId: $claim->admin_id ? (int) $claim->admin_id : null,
            carrierClaimReference: $claim->carrier_claim_reference,
            notes: $claim->notes,
            createdAt: $claim->created_at,
        );
    }
}
