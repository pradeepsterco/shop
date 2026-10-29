<?php

namespace App\Shipping\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A return pickup (reverse shipment) was delivered to the seller.
 */
class ShipmentReturnReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $shipmentId,
        public string $sellerOrderId,
        public ?string $reason = null,
    ) {}
}
