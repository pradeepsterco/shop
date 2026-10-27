<?php

namespace App\Orders\Services;

use App\Coupons\Enums\RedemptionStatus;
use App\Coupons\Models\CouponRedemption;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;

class CouponOrderReportingService
{
    /**
     * Calculate total net discount given for a single coupon (in paise),
     * strictly excluding discounts on cancelled and RTO seller orders.
     */
    public function getTotalDiscountGiven(int $couponId): int
    {
        $map = $this->getTotalDiscountsGivenForCoupons([$couponId]);

        return $map[$couponId] ?? 0;
    }

    /**
     * Calculate total net discount given in bulk for multiple coupons.
     *
     * @param  array<int>  $couponIds
     * @return array<int, int> Map of coupon_id => net paise given
     */
    public function getTotalDiscountsGivenForCoupons(array $couponIds): array
    {
        if (empty($couponIds)) {
            return [];
        }

        $redemptions = CouponRedemption::whereIn('coupon_id', $couponIds)->get();
        if ($redemptions->isEmpty()) {
            return array_fill_keys($couponIds, 0);
        }

        $orderNumbers = $redemptions->pluck('order_reference')->unique()->all();
        $orders = Order::with('sellerOrders')
            ->whereIn('order_number', $orderNumbers)
            ->get()
            ->keyBy('order_number');

        $result = array_fill_keys($couponIds, 0);

        foreach ($redemptions as $redemption) {
            $cId = (int) $redemption->coupon_id;
            /** @var Order|null $order */
            $order = $orders->get($redemption->order_reference);

            if ($order && $order->sellerOrders->isNotEmpty()) {
                foreach ($order->sellerOrders as $sellerOrder) {
                    if (! in_array($sellerOrder->status, [SellerOrderStatus::Cancelled, SellerOrderStatus::ReturnedToOrigin], true)) {
                        $result[$cId] += (int) $sellerOrder->coupon_discount_total;
                    }
                }
            } else {
                if ($redemption->status !== RedemptionStatus::Released) {
                    $result[$cId] += max(0, (int) $redemption->discount_amount - (int) $redemption->released_amount);
                }
            }
        }

        return $result;
    }

    /**
     * Get display reporting status for a single redemption row:
     *
     * @return array{label: string, badge_class: string, discount_given: int}
     */
    public function getRedemptionDisplayStatus(CouponRedemption $redemption, ?Order $order = null): array
    {
        $order = $order ?? Order::with('sellerOrders')->where('order_number', $redemption->order_reference)->first();

        if ($order && $order->sellerOrders->isNotEmpty()) {
            $sellerOrders = $order->sellerOrders;
            $allFailed = $sellerOrders->every(
                fn ($so) => in_array($so->status, [SellerOrderStatus::Cancelled, SellerOrderStatus::ReturnedToOrigin], true)
            );

            if ($allFailed) {
                if ($redemption->status === RedemptionStatus::Released) {
                    return [
                        'label' => 'Released (Cancelled)',
                        'badge_class' => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
                        'discount_given' => 0,
                    ];
                }

                // If not released (e.g. RTO when restore_usage_on_rto is false)
                return [
                    'label' => 'Consumed — order not delivered',
                    'badge_class' => 'bg-amber-50 text-amber-800 border border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800',
                    'discount_given' => 0,
                ];
            }

            // Calculate active/delivered discount
            $activeDiscount = 0;
            foreach ($sellerOrders as $so) {
                if (! in_array($so->status, [SellerOrderStatus::Cancelled, SellerOrderStatus::ReturnedToOrigin], true)) {
                    $activeDiscount += (int) $so->coupon_discount_total;
                }
            }

            if ($redemption->status === RedemptionStatus::PartiallyReleased) {
                return [
                    'label' => 'Partially Delivered',
                    'badge_class' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
                    'discount_given' => $activeDiscount,
                ];
            }

            return [
                'label' => 'Redeemed',
                'badge_class' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
                'discount_given' => $activeDiscount,
            ];
        }

        if ($redemption->status === RedemptionStatus::Released) {
            return [
                'label' => 'Released',
                'badge_class' => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
                'discount_given' => 0,
            ];
        }

        return [
            'label' => $redemption->status->label(),
            'badge_class' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
            'discount_given' => (int) $redemption->discount_amount - (int) $redemption->released_amount,
        ];
    }
}

