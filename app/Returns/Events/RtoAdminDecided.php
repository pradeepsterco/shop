<?php

namespace App\Returns\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RtoAdminDecided
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $reportId,
        public string $sellerOrderId,
        public string $decision,
        public int $adminId,
    ) {}
}
