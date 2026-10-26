<?php

namespace App\Fees\Contracts;

use App\Fees\DTOs\FeeBreakdownDto;
use App\Fees\DTOs\SellerOrderFeeSummaryDto;

interface OrderItemFeeSnapshotLookupInterface
{
    /**
     * Get item fee snapshots for a seller order, strictly scoped by the seller's profile ID.
     *
     * @return array<int, FeeBreakdownDto>
     */
    public function getSnapshotsForSellerOrder(string $sellerOrderId, int $sellerProfileId): array;

    /**
     * Get aggregate fee summary for a seller order package.
     */
    public function getSellerOrderFeeSummary(string $sellerOrderId, int $sellerProfileId): ?SellerOrderFeeSummaryDto;

    /**
     * Get item fee snapshots for a seller order without seller scoping (internal backend modules).
     *
     * @return array<int, FeeBreakdownDto>
     */
    public function getInternalSnapshotsForSellerOrder(string $sellerOrderId): array;
}
