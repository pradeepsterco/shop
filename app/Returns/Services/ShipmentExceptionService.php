<?php

namespace App\Returns\Services;

use App\Orders\Contracts\CustomerRefundServiceInterface;
use App\Orders\Contracts\SellerOrderFulfillmentInterface;
use App\Returns\Contracts\CourierClaimServiceInterface;
use App\Returns\Contracts\ShipmentExceptionServiceInterface;
use App\Returns\Models\PlatformLoss;
use App\Returns\Models\UndeliveredItem;
use App\Settlements\Contracts\SettlementServiceInterface;
use App\Shipping\Contracts\ShipmentLookupInterface;
use App\Shipping\Contracts\ShipmentServiceInterface;
use App\Shipping\Enums\ShipmentPurpose;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class ShipmentExceptionService implements ShipmentExceptionServiceInterface
{
    public function __construct(
        protected SettlementServiceInterface $settlementService,
        protected SellerOrderFulfillmentInterface $orderFulfillment,
        protected CourierClaimServiceInterface $claimService,
        protected ShipmentServiceInterface $shipmentService,
        protected ShipmentLookupInterface $shipmentLookup,
        protected CustomerRefundServiceInterface $refunds,
    ) {}

    public function confirmLostShipment(
        string $shipmentId,
        string $sellerOrderId,
        string $carrierName,
        int $declaredValuePaise,
        int $adminId,
        ?string $notes = null,
    ): void {
        if (config('returns.courier_loss_bearer', 'platform') !== 'platform') {
            throw new DomainException('Only platform-borne courier losses are supported.');
        }

        DB::transaction(function () use ($shipmentId, $sellerOrderId, $carrierName, $declaredValuePaise, $adminId, $notes) {
            $alreadyConfirmed = PlatformLoss::where('shipment_id', $shipmentId)
                ->where('loss_type', PlatformLoss::TYPE_LOST_SHIPMENT)
                ->lockForUpdate()
                ->exists();
            if ($alreadyConfirmed) {
                throw new DomainException('This lost shipment has already been confirmed.');
            }

            if ($this->shipmentLookup->getShipmentById($shipmentId)?->purpose === ShipmentPurpose::Reship) {
                // Lost reship: the same lost flow, for the reshipped units only (the order stays partially delivered)
                $lossAmount = $this->absorbLostReship($shipmentId);
            } else {
                $settlement = $this->settlementService->confirmLostShipmentSettlement($sellerOrderId, $adminId, $notes);
                $lossAmount = (int) ($settlement->net_payout ?? 0);

                $this->orderFulfillment->markLostInTransit($sellerOrderId, 'Shipment lost in transit by courier; customer not charged');
            }

            $claim = $this->claimService->recordClaim(
                shipmentId: $shipmentId,
                sellerOrderId: $sellerOrderId,
                carrierName: $carrierName,
                claimType: 'lost_shipment',
                claimAmountPaise: $declaredValuePaise,
                bearer: 'platform',
                adminId: $adminId,
                notes: $notes ?? 'Shipment confirmed lost by administration (Platform bears loss)',
            );

            PlatformLoss::create([
                'loss_type' => PlatformLoss::TYPE_LOST_SHIPMENT,
                'seller_order_id' => $sellerOrderId,
                'shipment_id' => $shipmentId,
                'courier_claim_id' => $claim->id,
                // The platform pays the seller this payout but collects nothing from the customer
                'loss_amount_paise' => max(0, $lossAmount),
                'declared_value_paise' => $declaredValuePaise,
                'admin_id' => $adminId,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Seller is paid for the reshipped units as if delivered, and the customer is not charged for them: the units are
     * closed with a (not collected) refund that also releases their coupon share. Returns the seller payout funded.
     */
    protected function absorbLostReship(string $reshipShipmentId): int
    {
        $items = UndeliveredItem::where('reship_shipment_id', $reshipShipmentId)
            ->where('status', UndeliveredItem::STATUS_RESHIPPING)
            ->lockForUpdate()
            ->get();

        $lines = [];
        foreach ($items as $item) {
            $lines += $item->lines();
        }

        $settlement = $this->settlementService->completeReshipSettlement($reshipShipmentId, $lines, CarbonImmutable::now());

        foreach ($items as $item) {
            $refund = $this->refunds->createRefund(
                sourceType: UndeliveredItem::REFUND_SOURCE,
                sourceId: $item->id,
                sellerOrderId: $item->seller_order_id,
                units: [$item->order_item_id => $item->units],
                customerPaid: $item->customer_paid || $this->refunds->customerChargedForUndeliveredUnits($item->seller_order_id),
            );

            $item->update(['status' => UndeliveredItem::STATUS_LOST, 'refund_id' => $refund->id]);
        }

        return (int) ($settlement->net_payout ?? 0);
    }

    public function resolvePartialDelivery(
        string $shipmentId,
        string $sellerOrderId,
        array $deliveredItemIds,
        array $undeliveredItemIds,
        int $adminId,
        ?string $notes = null,
    ): void {
        DB::transaction(function () use ($shipmentId, $sellerOrderId, $deliveredItemIds, $undeliveredItemIds, $adminId, $notes) {
            $this->shipmentService->resolvePartialDelivery($shipmentId, $deliveredItemIds, $undeliveredItemIds, $adminId, $notes);

            $undeliveredLines = $this->orderFulfillment->confirmPartialDelivery($sellerOrderId, $deliveredItemIds, $undeliveredItemIds);

            $this->settlementService->releasePartialDeliveryHold($sellerOrderId, $undeliveredItemIds);

            foreach ($undeliveredLines as $orderItemId => $line) {
                UndeliveredItem::create([
                    'seller_order_id' => $sellerOrderId,
                    'shipment_id' => $shipmentId,
                    'order_item_id' => (int) $orderItemId,
                    'units' => $line['units'],
                    'line_qty' => $line['line_qty'],
                    'status' => UndeliveredItem::STATUS_PENDING,
                    'recorded_by_admin_id' => $adminId,
                ]);
            }
        });
    }
}
