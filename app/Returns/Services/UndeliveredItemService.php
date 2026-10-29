<?php

namespace App\Returns\Services;

use App\Orders\Contracts\CustomerRefundServiceInterface;
use App\Orders\Contracts\OrderRestockInterface;
use App\Orders\Contracts\SellerOrderFulfillmentInterface;
use App\Returns\Contracts\UndeliveredItemServiceInterface;
use App\Returns\Models\UndeliveredItem;
use App\Settlements\Contracts\SettlementServiceInterface;
use App\Shipping\Contracts\ShipmentLookupInterface;
use App\Shipping\Contracts\ShipmentServiceInterface;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class UndeliveredItemService implements UndeliveredItemServiceInterface
{
    public function __construct(
        protected CustomerRefundServiceInterface $refunds,
        protected OrderRestockInterface $inventory,
        protected SellerOrderFulfillmentInterface $orderFulfillment,
        protected ShipmentServiceInterface $shipments,
        protected ShipmentLookupInterface $shipmentLookup,
        protected SettlementServiceInterface $settlements,
    ) {}

    public function refund(string $undeliveredItemId, int $adminId): UndeliveredItem
    {
        return DB::transaction(function () use ($undeliveredItemId, $adminId) {
            $item = $this->lockPending($undeliveredItemId);

            // Settlement reversals for these units already happened at partial delivery: refund only touches the customer side
            $refund = $this->refunds->createRefund(
                sourceType: UndeliveredItem::REFUND_SOURCE,
                sourceId: $item->id,
                sellerOrderId: $item->seller_order_id,
                units: [$item->order_item_id => $item->units],
                customerPaid: $this->customerPaid($item),
            );

            $item->update([
                'status' => UndeliveredItem::STATUS_REFUNDED,
                'resolution' => UndeliveredItem::RESOLUTION_REFUND,
                'refund_id' => $refund->id,
                'resolved_at' => now(),
                'resolved_by_admin_id' => $adminId,
            ]);

            return $item->fresh();
        });
    }

    public function reship(string $undeliveredItemId, int $adminId): UndeliveredItem
    {
        // 1. Database work only: reserve stock, create the pending reship, charge reship shipping, update the item
        $item = DB::transaction(function () use ($undeliveredItemId, $adminId) {
            $item = $this->lockPending($undeliveredItemId);
            $units = [$item->order_item_id => $item->units];

            // Throws (and rolls everything back) when out of stock or the listing is not active: refund instead
            $this->inventory->reserveStockForReship($item->order_item_id, $item->units);

            $shipment = $this->shipments->createReshipBooking(
                sellerOrderId: $item->seller_order_id,
                units: $units,
                // A customer who already paid for these units (missing-item claim) owes nothing on the reship
                codAmountPaise: $item->customer_paid ? 0 : $this->refunds->amountOwedForUnits($item->seller_order_id, $units),
                declaredValuePaise: $this->refunds->unitsValue($item->seller_order_id, $units),
            );

            $this->settlements->startReshipSettlement($item->seller_order_id, $shipment->id, $item->lines());

            $item->update([
                'status' => UndeliveredItem::STATUS_RESHIPPING,
                'resolution' => UndeliveredItem::RESOLUTION_RESHIP,
                'reship_shipment_id' => $shipment->id,
                'reship_attempts' => $item->reship_attempts + 1,
                'resolved_at' => now(),
                'resolved_by_admin_id' => $adminId,
            ]);

            return $item->fresh();
        });

        // 2. Courier booking after commit, with no locks held. A failure leaves a retryable booking_failed reship;
        // stock and the shipping charge are not applied again on retry.
        $this->shipments->bookShipment((string) $item->reship_shipment_id);

        return $item->fresh();
    }

    protected function customerPaid(UndeliveredItem $item): bool
    {
        return $item->customer_paid || $this->refunds->customerChargedForUndeliveredUnits($item->seller_order_id);
    }

    public function reshipDelivered(string $reshipShipmentId, string $deliveredAt): void
    {
        DB::transaction(function () use ($reshipShipmentId, $deliveredAt) {
            $items = $this->lockReshipping($reshipShipmentId);
            if ($items->isEmpty()) {
                return;
            }

            $sellerOrderId = $items->first()->seller_order_id;

            $this->settlements->completeReshipSettlement($reshipShipmentId, $this->linesOf($items), CarbonImmutable::parse($deliveredAt));

            UndeliveredItem::whereIn('id', $items->pluck('id'))->update(['status' => UndeliveredItem::STATUS_RESHIPPED]);

            $shipment = $this->shipmentLookup->getShipmentById($reshipShipmentId);
            $allDelivered = ! UndeliveredItem::where('seller_order_id', $sellerOrderId)
                ->where('status', '!=', UndeliveredItem::STATUS_RESHIPPED)
                ->exists();

            $this->orderFulfillment->recordReshipDelivered(
                sellerOrderId: $sellerOrderId,
                codCollectedPaise: $shipment && $shipment->isCod ? $shipment->codAmountPaise : 0,
                allItemsDelivered: $allDelivered,
            );
        });
    }

    public function reshipReturned(string $reshipShipmentId): void
    {
        DB::transaction(function () use ($reshipShipmentId) {
            $items = $this->lockReshipping($reshipShipmentId);
            if ($items->isEmpty()) {
                return;
            }

            // The units are back with the seller; the seller still pays the reship and its RTO shipping
            $this->settlements->returnReshipSettlement($reshipShipmentId);
            $this->backToQueue($items);
        });
    }

    public function reshipCancelled(string $reshipShipmentId): void
    {
        DB::transaction(function () use ($reshipShipmentId) {
            $items = $this->lockReshipping($reshipShipmentId);
            if ($items->isEmpty()) {
                return;
            }

            $this->settlements->cancelReshipSettlement($reshipShipmentId);
            $this->backToQueue($items);
        });
    }

    protected function lockPending(string $undeliveredItemId): UndeliveredItem
    {
        /** @var UndeliveredItem $item */
        $item = UndeliveredItem::lockForUpdate()->findOrFail($undeliveredItemId);

        // One decision at a time: a second click, or refund after reship (and vice versa), lands here
        if ($item->status !== UndeliveredItem::STATUS_PENDING) {
            throw new DomainException("This item has already been decided ({$item->status}).");
        }

        return $item;
    }

    /**
     * @return Collection<int, UndeliveredItem>
     */
    protected function lockReshipping(string $reshipShipmentId): Collection
    {
        return UndeliveredItem::where('reship_shipment_id', $reshipShipmentId)
            ->where('status', UndeliveredItem::STATUS_RESHIPPING)
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, UndeliveredItem>  $items
     * @return array<int, array{units: int, line_qty: int}>
     */
    protected function linesOf(Collection $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $lines += $item->lines();
        }

        return $lines;
    }

    /**
     * @param  Collection<int, UndeliveredItem>  $items
     */
    protected function backToQueue(Collection $items): void
    {
        foreach ($items as $item) {
            $this->inventory->restockOrderItemUnits($item->order_item_id, $item->units);

            $item->update([
                'status' => UndeliveredItem::STATUS_PENDING,
                'resolution' => null,
                'reship_shipment_id' => null,
                'resolved_at' => null,
                'resolved_by_admin_id' => null,
            ]);
        }
    }
}
