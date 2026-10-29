<?php

namespace App\Settlements\Listeners;

use App\Orders\Events\SellerOrderReturnedToOrigin;
use App\Settlements\Contracts\SettlementServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class ProcessRtoSettlementOnOrderReturned implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        protected SettlementServiceInterface $settlementService,
    ) {}

    public function handle(SellerOrderReturnedToOrigin $event): void
    {
        $this->settlementService->processRtoSettlement($event->sellerOrderId);
    }
}
