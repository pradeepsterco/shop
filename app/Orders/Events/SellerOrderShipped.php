<?php

namespace App\Orders\Events;

use DateTimeInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SellerOrderShipped
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sellerOrderId,
        public int $sellerProfileId,
        public DateTimeInterface $timestamp,
        public string $actorType,
        public int $actorId,
    ) {}
}
