<?php

namespace App\Returns\Listeners;

use App\Returns\Contracts\UndeliveredItemServiceInterface;
use App\Shipping\Events\ShipmentReshipCancelled;
use App\Shipping\Events\ShipmentReshipDelivered;
use App\Shipping\Events\ShipmentReshipReturned;

/**
 * Courier outcomes of a reship of undelivered items, applied to the undelivered-items queue.
 */
class HandleReshipOutcome
{
    public function __construct(
        protected UndeliveredItemServiceInterface $undeliveredItems,
    ) {}

    public function delivered(ShipmentReshipDelivered $event): void
    {
        $this->undeliveredItems->reshipDelivered($event->shipmentId, $event->deliveredAt);
    }

    public function returned(ShipmentReshipReturned $event): void
    {
        $this->undeliveredItems->reshipReturned($event->shipmentId);
    }

    public function cancelled(ShipmentReshipCancelled $event): void
    {
        $this->undeliveredItems->reshipCancelled($event->shipmentId);
    }
}
