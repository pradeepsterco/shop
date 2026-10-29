<?php

namespace App\Shipping\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShipmentPartialDelivered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int>  $deliveredItemIds
     * @param  array<int>  $undeliveredItemIds
     */
    public function __construct(
        public string $shipmentId,
        public string $sellerOrderId,
        public array $deliveredItemIds = [],
        public array $undeliveredItemIds = [],
    ) {}
}
