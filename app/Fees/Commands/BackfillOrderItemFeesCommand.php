<?php

namespace App\Fees\Commands;

use App\Fees\Contracts\FeeCalculatorInterface;
use App\Fees\Contracts\FeeScheduleServiceInterface;
use App\Fees\DTOs\FeeQuoteInput;
use App\Fees\Events\FeesCalculated;
use App\Fees\Models\OrderItemFeeSnapshot;
use App\Orders\Contracts\OrderItemsFeeLookupInterface;
use App\Orders\DTOs\OrderItemFeeContextDto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillOrderItemFeesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fees:backfill
                            {--limit= : Limit the number of order items to inspect}
                            {--dry-run : Simulate calculations without saving snapshots}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate and store fee snapshots for existing order items missing snapshots.';

    public function handle(
        OrderItemsFeeLookupInterface $lookup,
        FeeCalculatorInterface $calculator,
        FeeScheduleServiceInterface $scheduleService,
    ): int {
        $this->info('Starting order item fee backfill...');

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE: No snapshots will be persisted.');
        }

        // Get all already snapshotted order item IDs
        $existingItemIds = OrderItemFeeSnapshot::pluck('order_item_id')->all();
        $this->line('Found '.count($existingItemIds).' items with existing fee snapshots.');

        // Get missing order items from Orders module via contract
        $missingItems = $lookup->getUnprocessedOrderItemsForBackfill($existingItemIds, $limit);

        if (empty($missingItems)) {
            $this->info('All order items have fee snapshots. Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info('Found '.count($missingItems).' order items needing fee snapshots.');

        // Group by seller order ID
        /** @var array<string, array<int, OrderItemFeeContextDto>> $grouped */
        $grouped = [];
        foreach ($missingItems as $item) {
            $grouped[$item->sellerOrderId][] = $item;
        }

        $successCount = 0;
        $skippedCount = 0;

        foreach ($grouped as $sellerOrderId => $items) {
            $firstItem = $items[0];
            $schedule = $scheduleService->getScheduleForDate($firstItem->placedAt);

            if (! $schedule) {
                $this->warn("No fee schedule covering {$firstItem->placedAt->format('Y-m-d H:i:s')} for seller order {$sellerOrderId}. Skipping ".count($items).' items.');
                $skippedCount += count($items);

                continue;
            }

            // Build Quote Inputs
            $quoteInputs = [];
            foreach ($items as $item) {
                $quoteInputs[] = new FeeQuoteInput(
                    pricePaise: $item->unitPricePaise,
                    productGstRate: $item->gstRate,
                    verticalId: $item->verticalId,
                    weightGrams: $item->weightGrams(),
                    sellerPickupState: $item->sellerPickupState,
                    sellerPickupPincode: $item->sellerPickupPincode,
                    deliveryState: $item->deliveryState,
                    deliveryPincode: $item->deliveryPincode,
                    paymentMethod: $item->paymentMethod,
                    sellerHasPan: $item->sellerHasPan,
                    effectiveDate: $item->placedAt,
                    qty: $item->qty,
                );
            }

            $breakdowns = $calculator->calculateForSellerPackage(
                items: $quoteInputs,
                pickupState: (string) $firstItem->sellerPickupState,
                pickupPincode: $firstItem->sellerPickupPincode,
                deliveryState: $firstItem->deliveryState,
                deliveryPincode: $firstItem->deliveryPincode,
                paymentMethod: $firstItem->paymentMethod,
                sellerHasPan: $firstItem->sellerHasPan,
                effectiveDate: $firstItem->placedAt,
            );

            if (! $dryRun) {
                $sellerDeductions = 0;
                $sellerNetPayout = 0;
                $sellerCredits = 0;

                DB::transaction(function () use ($items, $breakdowns, $firstItem, $sellerOrderId, &$sellerDeductions, &$sellerNetPayout, &$sellerCredits) {
                    foreach ($items as $idx => $contextItem) {
                        $breakdown = $breakdowns[$idx];

                        $sellerDeductions += $breakdown->totalDeductions->getPaise();
                        $sellerNetPayout += $breakdown->netPayout->getPaise();
                        $sellerCredits += $breakdown->claimableCredits->getPaise();

                        OrderItemFeeSnapshot::firstOrCreate(
                            ['order_item_id' => $contextItem->orderItemId],
                            [
                                'seller_order_id' => $sellerOrderId,
                                'order_id' => $contextItem->orderId,
                                'fee_schedule_id' => $breakdown->scheduleId,
                                'schedule_version' => $breakdown->scheduleVersion,
                                'selling_price' => $breakdown->sellingPrice->getPaise(),
                                'qty' => $breakdown->qty,
                                'product_gst_rate' => $contextItem->gstRate,
                                'commission_amount' => $breakdown->commission->getPaise(),
                                'closing_fee_amount' => $breakdown->closingFee->getPaise(),
                                'shipping_fee_amount' => $breakdown->shippingFee->getPaise(),
                                'collection_fee_amount' => $breakdown->collectionFee->getPaise(),
                                'gst_on_fees_amount' => $breakdown->gstOnFees->getPaise(),
                                'tcs_amount' => $breakdown->tcs->getPaise(),
                                'tds_amount' => $breakdown->tds->getPaise(),
                                'total_deductions' => $breakdown->totalDeductions->getPaise(),
                                'net_payout' => $breakdown->netPayout->getPaise(),
                                'claimable_credits' => $breakdown->claimableCredits->getPaise(),
                                'snapshot_data' => array_merge($breakdown->toArray(), [
                                    'seller_profile_id' => $firstItem->sellerProfileId,
                                ]),
                            ]
                        );
                    }
                });

                event(new FeesCalculated($firstItem->orderId, [
                    $sellerOrderId => [
                        'total_deductions_paise' => $sellerDeductions,
                        'net_payout_paise' => $sellerNetPayout,
                        'claimable_credits_paise' => $sellerCredits,
                    ],
                ]));
            }

            $successCount += count($items);
        }

        $this->info("Backfill complete. Processed: {$successCount}, Skipped: {$skippedCount}.");

        return self::SUCCESS;
    }
}
