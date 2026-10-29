<?php

namespace App\Shipping\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The courier gave up on a return pickup after failed attempts.
 */
class ShipmentReturnPickupCancelled
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $shipmentId,
        public string $sellerOrderId,
        public ?string $reason = null,
    ) {}
}
