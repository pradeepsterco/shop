<?php

namespace App\Shipping\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The courier could not pick up a return from the customer; it may re-attempt.
 */
class ShipmentReturnPickupFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $shipmentId,
        public string $sellerOrderId,
        public int $attemptCount = 1,
        public ?string $reason = null,
    ) {}
}
