<?php

namespace App\Returns\Services;

use App\Orders\Contracts\OrderRestockInterface;
use App\Returns\Contracts\RtoIntakeServiceInterface;
use App\Returns\DTOs\RtoIntakeReportDto;
use App\Returns\Enums\RtoAdminDecision;
use App\Returns\Enums\RtoConditionStatus;
use App\Returns\Events\RtoAdminDecided;
use App\Returns\Events\RtoConditionReported;
use App\Returns\Models\RtoIntakeReport;
use App\Shipping\Contracts\ShipmentLookupInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class RtoIntakeService implements RtoIntakeServiceInterface
{
    public function __construct(
        protected ShipmentLookupInterface $shipmentLookup,
        protected OrderRestockInterface $orderRestock,
    ) {}

    public function submitRtoReport(
        string $sellerOrderId,
        string $conditionStatus,
        ?string $notes = null,
        ?array $photos = null,
    ): RtoIntakeReportDto {
        $statusEnum = RtoConditionStatus::tryFrom($conditionStatus);
        if (! $statusEnum) {
            throw new DomainException("Invalid RTO condition status: {$conditionStatus}");
        }

        $shipment = $this->shipmentLookup->getShipmentForSellerOrder($sellerOrderId);
        if (! $shipment) {
            throw new DomainException("Shipment not found for seller order {$sellerOrderId}");
        }

        /** @var RtoIntakeReport $report */
        $report = DB::transaction(function () use ($sellerOrderId, $shipment, $statusEnum, $notes, $photos) {
            // A submitted report is read-only: the seller cannot overwrite it (or reset an admin decision)
            if (RtoIntakeReport::where('seller_order_id', $sellerOrderId)->lockForUpdate()->exists()) {
                throw new DomainException('An RTO condition report has already been submitted for this order.');
            }

            return RtoIntakeReport::create([
                'seller_order_id' => $sellerOrderId,
                'shipment_id' => $shipment->id,
                'seller_profile_id' => $shipment->sellerProfileId,
                'condition_status' => $statusEnum,
                'notes' => $notes,
                'photos' => $photos ?? [],
                'reported_at' => now(),
                'admin_decision' => RtoAdminDecision::Pending,
            ]);
        });

        event(new RtoConditionReported(
            reportId: $report->id,
            sellerOrderId: $sellerOrderId,
            shipmentId: $shipment->id,
            sellerProfileId: (int) $shipment->sellerProfileId,
            conditionStatus: $statusEnum->value,
        ));

        return $this->toDto($report);
    }

    public function decideRtoReport(
        string $sellerOrderId,
        string $decision,
        int $adminId,
        ?string $adminNotes = null,
    ): RtoIntakeReportDto {
        $decisionEnum = RtoAdminDecision::tryFrom($decision);
        if (! $decisionEnum || $decisionEnum === RtoAdminDecision::Pending) {
            throw new DomainException("Invalid RTO admin decision: {$decision}");
        }

        /** @var RtoIntakeReport|null $report */
        $report = RtoIntakeReport::where('seller_order_id', $sellerOrderId)->first();

        // Restock adds stock and write-off is final: a decision can only be made once
        if ($report && $report->admin_decision !== RtoAdminDecision::Pending) {
            throw new DomainException("RTO decision already recorded ({$report->admin_decision->value}).");
        }

        // If seller missed deadline or didn't report, create an admin-initiated report
        if (! $report) {
            $shipment = $this->shipmentLookup->getShipmentForSellerOrder($sellerOrderId);
            if (! $shipment) {
                throw new DomainException("Shipment not found for seller order {$sellerOrderId}");
            }

            /** @var RtoIntakeReport $report */
            $report = RtoIntakeReport::create([
                'seller_order_id' => $sellerOrderId,
                'shipment_id' => $shipment->id,
                'seller_profile_id' => $shipment->sellerProfileId,
                'condition_status' => RtoConditionStatus::Intact,
                'notes' => 'Seller did not submit inspection report; decided directly by admin',
                'photos' => [],
                'reported_at' => now(),
                'admin_decision' => RtoAdminDecision::Pending,
            ]);
        }

        // If restocking, trigger order restock & buy box refresh
        if ($decisionEnum === RtoAdminDecision::Restocked) {
            $this->orderRestock->restockSellerOrder($sellerOrderId);
        }

        $report->update([
            'admin_decision' => $decisionEnum,
            'admin_decided_at' => now(),
            'admin_id' => $adminId,
            'admin_notes' => $adminNotes,
        ]);

        event(new RtoAdminDecided(
            reportId: $report->id,
            sellerOrderId: $sellerOrderId,
            decision: $decisionEnum->value,
            adminId: $adminId,
        ));

        return $this->toDto($report);
    }

    protected function toDto(RtoIntakeReport $report): RtoIntakeReportDto
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
}
