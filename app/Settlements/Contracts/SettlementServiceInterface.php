<?php

namespace App\Settlements\Contracts;

use App\Settlements\DTOs\SettlementSummaryDto;
use App\Settlements\Models\SellerOrderSettlement;
use Carbon\CarbonImmutable;

interface SettlementServiceInterface
{
    /**
     * Create a settlement and its ledger entries for a seller order.
     */
    public function createSettlementForSellerOrder(string $sellerOrderId): ?SellerOrderSettlement;

    /**
     * Mark a settlement as on hold (typically when order is delivered).
     */
    public function markSettlementOnHold(string $sellerOrderId, ?CarbonImmutable $deliveredAt = null): ?SellerOrderSettlement;

    /**
     * Void a settlement and record reversals (when order is cancelled).
     */
    public function voidSettlement(string $sellerOrderId): ?SellerOrderSettlement;

    /**
     * Release all matured on_hold settlements whose return window has passed.
     */
    public function releaseMaturedSettlements(): int;

    /**
     * Reconcile a settlement whose state does not match the seller order.
     */
    public function reconcileSettlementState(SellerOrderSettlement $settlement): bool;

    /**
     * Find settlement by seller order id.
     */
    public function getSettlementBySellerOrderId(string $sellerOrderId): ?SellerOrderSettlement;

    /**
     * Get summary DTO for a settlement.
     */
    public function getSettlementSummary(SellerOrderSettlement $settlement): SettlementSummaryDto;
}
