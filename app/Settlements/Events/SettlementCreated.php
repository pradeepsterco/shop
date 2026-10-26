<?php

namespace App\Settlements\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SettlementCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $settlementId,
        public readonly string $sellerOrderId,
        public readonly int $sellerId,
        public readonly int $netPaise,
    ) {}
}
