<?php

namespace App\Settlements\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class PayoutBatchCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $batchId,
        public readonly int $totalPaise,
        public readonly int $payoutCount,
    ) {}
}
