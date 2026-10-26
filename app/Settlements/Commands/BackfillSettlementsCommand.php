<?php

namespace App\Settlements\Commands;

use App\Settlements\Contracts\SettlementServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillSettlementsCommand extends Command
{
    protected $signature = 'settlements:backfill {--seller-order= : Backfill a specific seller order ID}';

    protected $description = 'Idempotently backfill settlements and ledger entries for orders with fee snapshots';

    public function handle(SettlementServiceInterface $settlementService): int
    {
        $this->info('Starting settlements backfill...');

        $specificSellerOrderId = $this->option('seller-order');

        $query = DB::table('order_item_fee_snapshots')
            ->select('seller_order_id')
            ->distinct();

        if ($specificSellerOrderId) {
            $query->where('seller_order_id', $specificSellerOrderId);
        }

        $sellerOrderIds = $query->pluck('seller_order_id');

        if ($sellerOrderIds->isEmpty()) {
            $this->warn('No fee snapshots found to backfill.');

            return Command::SUCCESS;
        }

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($sellerOrderIds as $sellerOrderId) {
            $existing = $settlementService->getSettlementBySellerOrderId((string) $sellerOrderId);

            if ($existing) {
                $settlementService->reconcileSettlementState($existing);
                $skippedCount++;

                continue;
            }

            $settlement = $settlementService->createSettlementForSellerOrder((string) $sellerOrderId);
            if ($settlement) {
                $settlementService->reconcileSettlementState($settlement);
                $createdCount++;
            } else {
                $skippedCount++;
            }
        }

        $this->info("Backfill complete. Created: {$createdCount}, Already existed/skipped: {$skippedCount}.");

        return Command::SUCCESS;
    }
}
