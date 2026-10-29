<?php

namespace App\Orders\Services;

use App\Models\ProductVariant;
use App\Orders\Contracts\ReturnableOrderLookupInterface;
use App\Orders\DTOs\ReturnableItemDto;
use App\Orders\DTOs\ReturnableOrderDto;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;

class ReturnableOrderLookupService implements ReturnableOrderLookupInterface
{
    public function forSellerOrder(string $sellerOrderId): ?ReturnableOrderDto
    {
        /** @var SellerOrder|null $sellerOrder */
        $sellerOrder = SellerOrder::with(['order', 'items'])->find($sellerOrderId);
        if (! $sellerOrder) {
            return null;
        }

        // Variants may be soft-deleted after the order; their vertical still decides returnability
        $variants = ProductVariant::withTrashed()
            ->with('product.vertical')
            ->whereIn('id', $sellerOrder->items->pluck('product_variant_id')->filter())
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($sellerOrder->items as $item) {
            /** @var OrderItem $item */
            $vertical = $item->product_variant_id ? $variants->get($item->product_variant_id)?->product?->vertical : null;

            $items[(int) $item->id] = new ReturnableItemDto(
                orderItemId: (int) $item->id,
                title: $item->title,
                qty: (int) $item->qty,
                unitPricePaise: (int) $item->unit_price,
                isReturnable: $vertical->is_returnable ?? true,
            );
        }

        return new ReturnableOrderDto(
            sellerOrderId: $sellerOrder->id,
            orderId: $sellerOrder->order_id,
            orderNumber: $sellerOrder->order->order_number,
            userId: (int) $sellerOrder->order->user_id,
            status: $sellerOrder->status,
            deliveredAt: $sellerOrder->delivered_at,
            items: $items,
            sellerProfileId: (int) $sellerOrder->seller_profile_id,
        );
    }
}
