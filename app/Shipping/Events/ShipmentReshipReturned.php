<?php

namespace App\Shipping\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A reship of undelivered items came back to the seller (RTO delivered).
 */
class ShipmentReshipReturned
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $shipmentId,
        public string $sellerOrderId,
        public ?string $reason = null,
    ) {}
}
