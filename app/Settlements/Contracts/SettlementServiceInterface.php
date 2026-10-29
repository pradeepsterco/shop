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
     * Process an RTO settlement: reverse sale + fees, debit RTO shipping, and carry net negative.
     */
    public function processRtoSettlement(string $sellerOrderId): ?SellerOrderSettlement;

    /**
     * Confirm a lost shipment where platform absorbs loss: seller settlement continues as delivered.
     */
    public function confirmLostShipmentSettlement(string $sellerOrderId, int $adminId, ?string $notes = null): ?SellerOrderSettlement;

    /**
     * Release the partial delivery hold after admin confirms delivered items: reverse sale and fees for the
     * undelivered items so the seller is paid only for what was delivered, then start the normal return window.
     *
     * @param  array<int>  $undeliveredOrderItemIds
     */
    public function releasePartialDeliveryHold(string $sellerOrderId, array $undeliveredOrderItemIds = []): ?SellerOrderSettlement;

    /**
     * A reship of undelivered units starts its own settlement (kind reship, keyed by the reship shipment) and debits
     * the seller the reship shipping. The window saved on the order's settlement is carried over.
     *
     * @param  array<int, array{units: int, line_qty: int}>  $lines
     */
    public function startReshipSettlement(string $sellerOrderId, string $reshipShipmentId, array $lines): SellerOrderSettlement;

    /**
     * Reship delivered (or lost and absorbed by the platform): re-credit sale and fees for those units and hold them for
     * the saved return window from $deliveredAt. No-op unless the reship settlement is pending.
     *
     * @param  array<int, array{units: int, line_qty: int}>  $lines
     */
    public function completeReshipSettlement(string $reshipShipmentId, array $lines, CarbonImmutable $deliveredAt): ?SellerOrderSettlement;

    /**
     * Reship returned to origin: debit reship RTO shipping; the negative settlement becomes eligible and is carried
     * into the seller's next payout (phase A RTO rule).
     */
    public function returnReshipSettlement(string $reshipShipmentId): ?SellerOrderSettlement;

    /**
     * Reship cancelled before it shipped: reverse the reship shipping debit and void the reship settlement.
     */
    public function cancelReshipSettlement(string $reshipShipmentId): ?SellerOrderSettlement;

    /**
     * An open customer return blocks release (and payout) of the package's settlement.
     */
    public function placeReturnHold(string $sellerOrderId): void;

    /**
     * The return was closed, rejected or resolved: the package's settlement follows its normal hold again.
     */
    public function releaseReturnHold(string $sellerOrderId): void;

    /**
     * The return window saved when the package was delivered, or null if it has not been delivered (or has no
     * settlement). Later edits to a vertical never change it.
     */
    public function savedReturnWindowDays(string $sellerOrderId): ?int;

    /**
     * Missing-item claim approved: reverse sale and fees for those units in a separate return settlement (kind return,
     * keyed by the return request), eligible and negative so it is carried into the seller's next payout.
     * Forward shipping stays charged. Returns the amount debited.
     *
     * @param  array<int, array{units: int, line_qty: int}>  $lines
     */
    public function reverseUnitsForReturnClaim(string $sellerOrderId, string $returnRequestId, array $lines): int;

    /**
     * Settle a decided customer return in its return settlement (kind return, keyed by the request), eligible so a
     * negative balance is carried into the seller's next payout even if the order was already paid out:
     *  - reverse_sale: per-unit sale reversal under return:{returnItemId}:{type}:{item} keys, fees too when
     *    reverse_fees, scaled by refund_ratio [refunded, refundable] for a partial refund;
     *  - shipping: charge key => multiplier, each charging the forward shipping allocated to the units x multiplier.
     * Returns the total debited.
     *
     * @param  array<int, array{units: int, line_qty: int, return_item_id: int}>  $lines
     * @param  array{reverse_sale: bool, reverse_fees: bool, refund_ratio: array{0: int, 1: int}, shipping: array<string, float>}  $instructions
     */
    public function settleReturn(string $sellerOrderId, string $returnRequestId, array $lines, array $instructions): int;

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
