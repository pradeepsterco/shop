<?php

namespace App\Orders\Adapters;

use App\Orders\Enums\SellerOrderStatus;
use App\Reviews\Contracts\ReviewEligibilityInterface;
use App\Reviews\DTOs\EligibilityResult;
use Illuminate\Support\Facades\DB;

class OrderDeliveredReviewEligibilityAdapter implements ReviewEligibilityInterface
{
    /**
     * Check if a customer is eligible to write or edit a review for a product.
     * Enforces genuine delivery verification via delivered_at and immutable order_status_histories,
     * preserves eligibility for delivered items subsequently returned/refunded,
     * and prevents already-claimed purchase items from creating duplicate reviews.
     */
    public function check(int $userId, int $productId): EligibilityResult
    {
        // 1. Check if customer already has a review for this product
        $existingReview = DB::table('product_reviews')
            ->where('reviewable_type', 'product')
            ->where('reviewable_id', $productId)
            ->where('user_id', $userId)
            ->first(['id', 'order_item_id', 'product_variant_id', 'seller_id']);

        if ($existingReview !== null) {
            return EligibilityResult::eligible(
                orderItemId: $existingReview->order_item_id !== null ? (string) $existingReview->order_item_id : null,
                variantId: $existingReview->product_variant_id !== null ? (int) $existingReview->product_variant_id : null,
                sellerId: $existingReview->seller_id !== null ? (int) $existingReview->seller_id : null,
                hasExistingReview: true,
                existingReviewId: (int) $existingReview->id,
            );
        }

        // 2. Query delivered order items for this user and product
        $deliveredItems = DB::table('order_items')
            ->join('seller_orders', 'order_items.seller_order_id', '=', 'seller_orders.id')
            ->join('orders', 'seller_orders.order_id', '=', 'orders.id')
            ->join('product_variants', 'order_items.product_variant_id', '=', 'product_variants.id')
            ->where('orders.user_id', $userId)
            ->where('product_variants.product_id', $productId)
            ->whereNotNull('seller_orders.delivered_at')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('order_status_histories')
                    ->whereColumn('order_status_histories.seller_order_id', 'seller_orders.id')
                    ->whereIn('order_status_histories.to_status', [
                        SellerOrderStatus::Delivered->value,
                        SellerOrderStatus::PartiallyDelivered->value,
                    ]);
            })
            ->select([
                'order_items.id as order_item_id',
                'order_items.product_variant_id',
                'seller_orders.seller_profile_id',
            ])
            ->orderByDesc('order_items.id')
            ->get();

        if ($deliveredItems->isEmpty()) {
            return EligibilityResult::ineligible('Only verified buyers who have received this product can write a review.');
        }

        // 3. Find an item not already claimed by a review
        $unclaimedItem = null;
        foreach ($deliveredItems as $item) {
            $alreadyClaimed = DB::table('product_reviews')
                ->where('order_item_id', (string) $item->order_item_id)
                ->exists();

            if (! $alreadyClaimed) {
                $unclaimedItem = $item;
                break;
            }
        }

        if ($unclaimedItem === null) {
            return EligibilityResult::ineligible('The purchased item(s) for this product have already been reviewed.');
        }

        return EligibilityResult::eligible(
            orderItemId: (string) $unclaimedItem->order_item_id,
            variantId: $unclaimedItem->product_variant_id !== null ? (int) $unclaimedItem->product_variant_id : null,
            sellerId: $unclaimedItem->seller_profile_id !== null ? (int) $unclaimedItem->seller_profile_id : null,
            hasExistingReview: false,
        );
    }
}
