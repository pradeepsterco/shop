<?php

namespace App\Fees\DTOs;

use App\Shared\ValueObjects\Money;

final readonly class SellerOrderFeeSummaryDto
{
    /**
     * @param  array<int, FeeBreakdownDto>  $itemBreakdowns
     */
    public function __construct(
        public string $sellerOrderId,
        public int $sellerProfileId,
        public array $itemBreakdowns,
        public Money $grossSellingPrice,
        public Money $commissionTotal,
        public Money $closingFeeTotal,
        public Money $shippingFeeTotal,
        public Money $collectionFeeTotal,
        public Money $totalMarketplaceFees,
        public Money $gstOnFeesTotal,
        public Money $tcsTotal,
        public Money $tdsTotal,
        public Money $totalDeductions,
        public Money $netPayout,
        public Money $claimableCredits,
    ) {}
}
