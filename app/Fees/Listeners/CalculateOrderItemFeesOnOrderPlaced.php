<?php

namespace App\Fees\Listeners;

use App\Fees\Contracts\FeeCalculatorInterface;
use App\Fees\Contracts\FeeScheduleServiceInterface;
use App\Fees\DTOs\FeeQuoteInput;
use App\Fees\Events\FeesCalculated;
use App\Fees\Models\OrderItemFeeSnapshot;
use App\Orders\Contracts\OrderItemsFeeLookupInterface;
use App\Orders\DTOs\OrderItemFeeContextDto;
use App\Orders\Events\OrderPlaced;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalculateOrderItemFeesOnOrderPlaced implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        private readonly ?OrderItemsFeeLookupInterface $lookup = null,
        private readonly ?FeeCalculatorInterface $calculator = null,
        private readonly ?FeeScheduleServiceInterface $scheduleService = null,
    ) {}

    public function handle(OrderPlaced $event): void
    {
        $lookup = $this->lookup ?? app(OrderItemsFeeLookupInterface::class);
        $calculator = $this->calculator ?? app(FeeCalculatorInterface::class);
        $scheduleService = $this->scheduleService ?? app(FeeScheduleServiceInterface::class);

        try {
            $orderItems = $lookup->getOrderItemsForFeeCalculation($event->orderId);

            if (empty($orderItems)) {
                return;
            }

            // Group order items by seller order
            /** @var array<string, array<int, OrderItemFeeContextDto>> $grouped */
            $grouped = [];
            foreach ($orderItems as $item) {
                $grouped[$item->sellerOrderId][] = $item;
            }

            $sellerOrderTotals = [];

            foreach ($grouped as $sellerOrderId => $items) {
                $firstItem = $items[0];
                $schedule = $scheduleService->getScheduleForDate($firstItem->placedAt);

                if (! $schedule) {
                    Log::warning("No covering fee schedule found for order {$event->orderId} placed at {$firstItem->placedAt->format('c')}. Skipping fee calculation for seller order {$sellerOrderId}.");

                    continue;
                }

                // Prepare fee quote inputs
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

                // Calculate package fees with allocated shipping
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

                $sellerDeductions = 0;
                $sellerNetPayout = 0;
                $sellerCredits = 0;

                DB::transaction(function () use ($items, $breakdowns, $firstItem, $sellerOrderId, $event, &$sellerDeductions, &$sellerNetPayout, &$sellerCredits) {
                    foreach ($items as $idx => $contextItem) {
                        $breakdown = $breakdowns[$idx];

                        $sellerDeductions += $breakdown->totalDeductions->getPaise();
                        $sellerNetPayout += $breakdown->netPayout->getPaise();
                        $sellerCredits += $breakdown->claimableCredits->getPaise();

                        // Idempotent insertion using unique constraint on order_item_id
                        OrderItemFeeSnapshot::firstOrCreate(
                            ['order_item_id' => $contextItem->orderItemId],
                            [
                                'seller_order_id' => $sellerOrderId,
                                'order_id' => $event->orderId,
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

                $sellerOrderTotals[$sellerOrderId] = [
                    'total_deductions_paise' => $sellerDeductions,
                    'net_payout_paise' => $sellerNetPayout,
                    'claimable_credits_paise' => $sellerCredits,
                ];
            }

            if (! empty($sellerOrderTotals)) {
                event(new FeesCalculated($event->orderId, $sellerOrderTotals));
            }
        } catch (Throwable $e) {
            Log::error("Error calculating order fees for order {$event->orderId}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    public function failed(OrderPlaced $event, Throwable $exception): void
    {
        Log::error("CalculateOrderItemFeesOnOrderPlaced failed for order {$event->orderId} after max retries: {$exception->getMessage()}");
    }
}
