<?php

namespace App\Orders\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SellerOrderReturnedToOrigin
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sellerOrderId,
        public string $orderId,
        public int $sellerProfileId,
        public ?string $reason = null,
    ) {}
}
