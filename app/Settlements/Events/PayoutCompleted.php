<?php

namespace App\Settlements\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class PayoutCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $payoutId,
        public readonly string $batchId,
        public readonly int $sellerId,
        public readonly int $amountPaise,
        public readonly string $utr,
    ) {}
}
