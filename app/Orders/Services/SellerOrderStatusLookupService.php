<?php

namespace App\Orders\Services;

use App\Orders\Contracts\SellerOrderStatusLookupInterface;
use App\Orders\DTOs\SellerOrderStatusDto;
use App\Orders\Models\SellerOrder;

class SellerOrderStatusLookupService implements SellerOrderStatusLookupInterface
{
    public function getSellerOrderStatus(string $sellerOrderId): ?SellerOrderStatusDto
    {
        $sellerOrder = SellerOrder::find($sellerOrderId);

        if (! $sellerOrder) {
            return null;
        }

        return new SellerOrderStatusDto(
            sellerOrderId: (string) $sellerOrder->id,
            sellerProfileId: (int) $sellerOrder->seller_profile_id,
            status: $sellerOrder->status->value,
            orderId: (string) $sellerOrder->order_id,
            deliveredAt: $sellerOrder->delivered_at,
            cancelledAt: $sellerOrder->cancelled_at,
            placedAt: $sellerOrder->placed_at,
        );
    }
}
