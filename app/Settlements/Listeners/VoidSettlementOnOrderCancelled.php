<?php

namespace App\Settlements\Listeners;

use App\Orders\Events\SellerOrderCancelled;
use App\Settlements\Contracts\SettlementServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class VoidSettlementOnOrderCancelled implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        protected SettlementServiceInterface $settlementService,
    ) {}

    public function handle(SellerOrderCancelled $event): void
    {
        $this->settlementService->voidSettlement($event->sellerOrderId);
    }

    public function failed(SellerOrderCancelled $event, Throwable $exception): void
    {
        Log::error('VoidSettlementOnOrderCancelled failed permanently for seller order '.$event->sellerOrderId, [
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
