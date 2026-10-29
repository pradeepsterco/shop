<?php

namespace App\Returns\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RtoConditionReported
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $reportId,
        public string $sellerOrderId,
        public string $shipmentId,
        public int $sellerProfileId,
        public string $conditionStatus,
    ) {}
}
