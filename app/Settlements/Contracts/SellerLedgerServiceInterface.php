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
