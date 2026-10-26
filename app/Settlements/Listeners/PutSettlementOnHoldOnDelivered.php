<?php

namespace App\Settlements\Listeners;

use App\Orders\Events\SellerOrderDelivered;
use App\Settlements\Contracts\SettlementServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class PutSettlementOnHoldOnDelivered implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        protected SettlementServiceInterface $settlementService,
    ) {}

    public function handle(SellerOrderDelivered $event): void
    {
        $deliveredAt = CarbonImmutable::parse($event->timestamp);
        $this->settlementService->markSettlementOnHold($event->sellerOrderId, $deliveredAt);
    }

    public function failed(SellerOrderDelivered $event, Throwable $exception): void
    {
        Log::error('PutSettlementOnHoldOnDelivered failed permanently for seller order '.$event->sellerOrderId, [
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
