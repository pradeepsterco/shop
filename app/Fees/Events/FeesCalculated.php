<?php

namespace App\Fees\Events;

use DateTimeInterface;
use Illuminate\Foundation\Events\Dispatchable;

final class FeesCalculated
{
    use Dispatchable;

    /**
     * @param  array<string, array{total_deductions_paise: int, net_payout_paise: int, claimable_credits_paise: int}>  $sellerOrderTotals
     */
    public function __construct(
        public string $orderId,
        public array $sellerOrderTotals,
        public ?DateTimeInterface $calculatedAt = null,
    ) {
        $this->calculatedAt = $calculatedAt ?? now();
    }
}
