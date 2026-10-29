<?php

namespace App\Orders\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SellerOrderPartialDelivered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sellerOrderId,
        public int $sellerProfileId,
        public ?string $reason = null,
    ) {}
}
