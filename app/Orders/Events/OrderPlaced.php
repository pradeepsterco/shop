<?php

namespace App\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPlaced
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, array{listing_id: int, qty: int}>  $lines
     */
    public function __construct(
        public string $orderId,
        public int $userId,
        public array $lines,
        public string $source = 'cart',
    ) {}
}
