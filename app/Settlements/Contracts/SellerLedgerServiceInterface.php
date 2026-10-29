<?php

namespace App\Settlements\Contracts;

use App\Settlements\DTOs\SellerBalanceDto;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use Illuminate\Database\Eloquent\Collection;

interface SellerLedgerServiceInterface
{
    /**
     * Record ledger entries for a newly created settlement from fee snapshots.
     *
     * @param  array<int, mixed>  $feeSnapshots
     */
    public function recordEntriesForSettlement(SellerOrderSettlement $settlement, array $feeSnapshots): void;

    /**
     * Record reversal entries when a settlement is voided.
     */
    public function recordReversalsForSettlement(SellerOrderSettlement $settlement): void;

    /**
     * Record RTO reversals: reverse sale and fees, keep forward shipping, and debit RTO shipping.
     */
    public function recordRtoReversalsForSettlement(SellerOrderSettlement $settlement): void;

    /**
     * Partial delivery: reverse sale and fees for the undelivered items; their allocated forward shipping is kept
     * (the whole package was shipped). Idempotent. Returns the sale and net amounts reversed by this call.
     *
     * @param  array<int>  $undeliveredOrderItemIds
     * @return array{sale: int, net: int}
     */
    public function recordPartialDeliveryReversals(SellerOrderSettlement $settlement, array $undeliveredOrderItemIds): array;

    /**
     * Debit reship shipping: the forward shipping originally allocated to the reshipped units x multiplier.
     * Idempotent. Returns the total reship shipping charged.
     *
     * @param  array<int, array{units: int, line_qty: int}>  $lines  order item id => units reshipped, whole line quantity
     */
    public function recordReshipShippingCharge(SellerOrderSettlement $reship, array $lines, float $multiplier): int;

    /**
     * Reship delivered: re-credit sale and fees for the reshipped units from the original ledger entries (the fee
     * snapshot taken at order time) under reship:{shipment}:... keys. Idempotent. Returns totals credited.
     *
     * @param  array<int, array{units: int, line_qty: int}>  $lines
     * @return array{sale: int, net: int}
     */
    public function recordReshipCredits(SellerOrderSettlement $reship, array $lines): array;

    /**
     * Reship returned to origin: debit RTO shipping = reship shipping x multiplier (the phase A RTO rule).
     */
    public function recordReshipRtoCharge(SellerOrderSettlement $reship, float $multiplier): int;

    /**
     * Reship cancelled before shipping: reverse the reship shipping debit. Returns the amount reversed.
     */
    public function reverseReshipShippingCharge(SellerOrderSettlement $reship): int;

    /**
     * Reverse sale and fees (not forward shipping) for some units of lines, from the original entries, under
     * {keyPrefix}:{type}:{item} keys. Fees are kept when $reverseFees is false; a partial refund reverses the
     * ratio share. Idempotent. Returns the net amount debited.
     *
     * @param  array<int, array{units: int, line_qty: int}>  $lines
     */
    public function recordUnitReversals(SellerOrderSettlement $settlement, array $lines, string $keyPrefix, bool $reverseFees = true, int $ratioNumerator = 1, int $ratioDenominator = 1): int;

    /**
     * Debit the forward shipping originally allocated to these units x multiplier, under {keyPrefix}{orderItemId}.
     * Idempotent. Returns the total charged under the prefix.
     *
     * @param  array<int, array{units: int, line_qty: int}>  $lines
     */
    public function recordAllocatedShippingCharge(SellerOrderSettlement $settlement, array $lines, string $keyPrefix, float $multiplier, string $description): int;

    /**
     * Compute current balances for a seller.
     */
    public function getSellerBalance(int $sellerId): SellerBalanceDto;

    /**
     * Retrieve all ledger entries for a settlement.
     *
     * @return Collection<int, SellerLedgerEntry>
     */
    public function getLedgerEntriesForSettlement(string $settlementId): Collection;
}
