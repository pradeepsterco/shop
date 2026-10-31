<?php

namespace App\Orders\Adapters;

use App\Helpdesk\Contracts\SellerOrderLookupInterface;
use App\Helpdesk\DTOs\SellerOrderRef;
use App\Orders\Models\SellerOrder;

class HelpdeskSellerOrderLookupAdapter implements SellerOrderLookupInterface
{
    public function findForSeller(int $sellerProfileId, string $sellerOrderId): ?SellerOrderRef
    {
        $sellerOrder = SellerOrder::query()
            ->with(['order', 'items'])
            ->where('seller_profile_id', $sellerProfileId)
            ->whereKey($sellerOrderId)
            ->first();

        return $sellerOrder ? $this->toRef($sellerOrder) : null;
    }

    public function recentForSeller(int $sellerProfileId, int $limit = 30): array
    {
        $sellerOrders = SellerOrder::query()
            ->with(['order', 'items'])
            ->where('seller_profile_id', $sellerProfileId)
            ->latest('placed_at')
            ->limit($limit)
            ->get();

        return array_values($sellerOrders->map(fn (SellerOrder $sellerOrder) => $this->toRef($sellerOrder))->all());
    }

    public function forOrder(string $orderId): array
    {
        $sellerOrders = SellerOrder::query()
            ->with(['order', 'items'])
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get();

        return array_values($sellerOrders->map(fn (SellerOrder $sellerOrder) => $this->toRef($sellerOrder))->all());
    }

    protected function toRef(SellerOrder $sellerOrder): SellerOrderRef
    {
        $titles = $sellerOrder->items->pluck('title');
        $summary = (string) $titles->first();
        if ($titles->count() > 1) {
            $summary .= ' +'.($titles->count() - 1).' more';
        }

        return new SellerOrderRef(
            sellerOrderId: (string) $sellerOrder->id,
            sellerProfileId: $sellerOrder->seller_profile_id,
            orderId: (string) $sellerOrder->order_id,
            orderNumber: $sellerOrder->order->order_number,
            placedAt: format_datetime($sellerOrder->placed_at),
            summary: $summary,
        );
    }
}
