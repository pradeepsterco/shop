<?php

namespace App\Orders\Services;

use App\Orders\Contracts\OrderItemsFeeLookupInterface;
use App\Orders\DTOs\OrderItemFeeContextDto;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;

class OrderItemsFeeLookupService implements OrderItemsFeeLookupInterface
{
    /**
     * @return array<int, OrderItemFeeContextDto>
     */
    public function getOrderItemsForFeeCalculation(string $orderId): array
    {
        $order = Order::with([
            'sellerOrders.sellerProfile',
            'sellerOrders.items.variant.product',
            'sellerOrders.items.listing.variant.product',
        ])->find($orderId);

        if (! $order) {
            return [];
        }

        $dtos = [];
        foreach ($order->sellerOrders as $sellerOrder) {
            foreach ($sellerOrder->items as $item) {
                $dtos[] = $this->buildDto($item, $sellerOrder, $order);
            }
        }

        return $dtos;
    }

    /**
     * @param  array<int, int>  $excludeOrderItemIds
     * @return array<int, OrderItemFeeContextDto>
     */
    public function getUnprocessedOrderItemsForBackfill(array $excludeOrderItemIds = [], ?int $limit = null): array
    {
        $query = OrderItem::query()
            ->with([
                'sellerOrder.order',
                'sellerOrder.sellerProfile',
                'variant.product',
                'listing.variant.product',
            ])
            ->when(! empty($excludeOrderItemIds), fn ($q) => $q->whereNotIn('id', $excludeOrderItemIds))
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $items = $query->get();
        $dtos = [];

        foreach ($items as $item) {
            $dtos[] = $this->buildDto($item, $item->sellerOrder, $item->sellerOrder->order);
        }

        return $dtos;
    }

    private function buildDto(OrderItem $item, SellerOrder $sellerOrder, Order $order): OrderItemFeeContextDto
    {
        $sellerProfile = $sellerOrder->sellerProfile;

        // Resolve vertical
        $verticalId = $item->variant?->product->vertical_id
            ?? $item->listing?->variant?->product->vertical_id;

        // Resolve weight in kg
        $weightKg = $item->variant->weight
            ?? $item->listing?->variant?->weight;

        // Resolve pickup state & pincode
        $sellerPickupState = $sellerOrder->seller_pickup_state
            ?: ($sellerProfile?->pickup_state);
        $sellerPickupPincode = $sellerProfile?->pickup_postal_code;

        // Resolve delivery state & pincode from order snapshot
        $deliveryState = $order->delivery_state
            ?: ($order->delivery_address['state'] ?? null);
        $deliveryPincode = $order->delivery_address['pincode'] ?? null;

        $sellerHasPan = $sellerProfile !== null && ! empty($sellerProfile->pan);
        $paymentMethod = $order->payment_method->value;
        $placedAt = $order->placed_at ?? $sellerOrder->placed_at ?? $order->created_at ?? now();

        return new OrderItemFeeContextDto(
            orderItemId: (int) $item->id,
            sellerOrderId: (string) $sellerOrder->id,
            orderId: (string) $order->id,
            sellerProfileId: (int) $sellerOrder->seller_profile_id,
            unitPricePaise: (int) $item->unit_price,
            qty: (int) $item->qty,
            gstRate: (float) $item->gst_rate,
            verticalId: $verticalId ? (int) $verticalId : null,
            weightKg: $weightKg !== null ? (float) $weightKg : null,
            sellerPickupState: $sellerPickupState,
            sellerPickupPincode: $sellerPickupPincode,
            deliveryState: $deliveryState,
            deliveryPincode: $deliveryPincode,
            paymentMethod: $paymentMethod,
            sellerHasPan: $sellerHasPan,
            placedAt: $placedAt,
        );
    }
}
