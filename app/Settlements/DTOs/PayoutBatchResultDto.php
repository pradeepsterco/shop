<?php

namespace App\Settlements\DTOs;

use App\Settlements\Models\SellerPayoutBatch;

final readonly class PayoutBatchResultDto
{
    /**
     * @param  array<int, array{seller_id: int, business_name: string, reason: string}>  $excludedSellers
     * @param  array<int, array{seller_id: int, business_name: string, reason: string}>  $manualReviewSellers
     */
    public function __construct(
        public ?SellerPayoutBatch $batch,
        public int $totalPaise,
        public int $payoutCount,
        public array $excludedSellers = [],
        public array $manualReviewSellers = [],
    ) {}
}
