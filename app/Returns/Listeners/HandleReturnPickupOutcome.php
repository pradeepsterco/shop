<?php

namespace App\Returns\Listeners;

use App\Orders\Events\SellerOrderShipped;
use App\Returns\Contracts\ReturnRequestServiceInterface;
use App\Shipping\Events\ShipmentReturnLost;
use App\Shipping\Events\ShipmentReturnPickupCancelled;
use App\Shipping\Events\ShipmentReturnReceived;

/**
 * Courier outcomes of a return pickup, applied to the customer's return request.
 * Failed attempts are counted on the shipment; the courier re-attempts until it cancels the pickup.
 */
class HandleReturnPickupOutcome
{
    public function __construct(
        protected ReturnRequestServiceInterface $returns,
    ) {}

    public function cancelled(ShipmentReturnPickupCancelled $event): void
    {
        $this->returns->pickupCancelled($event->shipmentId, $event->reason);
    }

    public function received(ShipmentReturnReceived $event): void
    {
        $this->returns->pickupReceived($event->shipmentId);
    }

    public function lost(ShipmentReturnLost $event): void
    {
        $this->returns->pickupLost($event->shipmentId);
    }

    /**
     * Any seller order shipped: if it is a replacement, the customer hears about it.
     */
    public function replacementShipped(SellerOrderShipped $event): void
    {
        $this->returns->replacementShipped($event->sellerOrderId);
    }
}
