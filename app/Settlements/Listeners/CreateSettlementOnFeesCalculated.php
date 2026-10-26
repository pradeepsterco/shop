<?php

namespace App\Settlements\Listeners;

use App\Fees\Events\FeesCalculated;
use App\Settlements\Contracts\SettlementServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateSettlementOnFeesCalculated implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        protected SettlementServiceInterface $settlementService,
    ) {}

    public function handle(FeesCalculated $event): void
    {
        foreach (array_keys($event->sellerOrderTotals) as $sellerOrderId) {
            $this->settlementService->createSettlementForSellerOrder((string) $sellerOrderId);
        }
    }

    public function failed(FeesCalculated $event, Throwable $exception): void
    {
        Log::error('CreateSettlementOnFeesCalculated failed permanently for order '.$event->orderId, [
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
