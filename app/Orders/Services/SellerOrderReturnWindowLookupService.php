<?php

namespace App\Orders\Services;

use App\Models\ProductVariant;
use App\Orders\Contracts\SellerOrderReturnWindowLookupInterface;
use App\Orders\Models\OrderItem;

class SellerOrderReturnWindowLookupService implements SellerOrderReturnWindowLookupInterface
{
    public function returnWindowDays(string $sellerOrderId): int
    {
        $defaultWindow = (int) config('returns.default_return_window_days', 7);
        $claimWindow = (int) config('returns.claim_window_days', 7);

        $variantIds = OrderItem::where('seller_order_id', $sellerOrderId)->pluck('product_variant_id');
        if ($variantIds->isEmpty()) {
            return $defaultWindow;
        }

        // Variants may be soft-deleted after the order; the window still comes from their vertical
        $verticals = ProductVariant::withTrashed()
            ->with('product.vertical')
            ->whereIn('id', $variantIds->filter())
            ->get()
            ->keyBy('id');

        // Items whose variant/vertical can't be resolved fall back to the default window
        return (int) $variantIds->map(function ($variantId) use ($verticals, $defaultWindow, $claimWindow) {
            $vertical = $variantId ? $verticals->get($variantId)?->product?->vertical : null;

            if (! $vertical) {
                return $defaultWindow;
            }

            return $vertical->is_returnable
                ? ($vertical->return_window_days ?? $defaultWindow)
                : $claimWindow;
        })->max();
    }
}
