<?php

namespace App\Orders\Adapters;

use App\Helpdesk\Contracts\CustomerOrderLookupInterface;
use App\Helpdesk\DTOs\CustomerOrderRef;
use App\Orders\Models\Order;

class HelpdeskCustomerOrderLookupAdapter implements CustomerOrderLookupInterface
{
    public function findForCustomer(int $userId, string $orderNumber): ?CustomerOrderRef
    {
        $order = Order::query()
            ->with('sellerOrders.items')
            ->where('user_id', $userId)
            ->where('order_number', $orderNumber)
            ->first();

        return $order ? $this->toRef($order) : null;
    }

    public function recentForCustomer(int $userId, int $limit = 20): array
    {
        $orders = Order::query()
            ->with('sellerOrders.items')
            ->where('user_id', $userId)
            ->latest('placed_at')
            ->limit($limit)
            ->get();

        return array_values($orders->map(fn (Order $order) => $this->toRef($order))->all());
    }

    protected function toRef(Order $order): CustomerOrderRef
    {
        $titles = $order->sellerOrders->flatMap(fn ($sellerOrder) => $sellerOrder->items->pluck('title'));
        $summary = (string) $titles->first();
        if ($titles->count() > 1) {
            $summary .= ' +'.($titles->count() - 1).' more';
        }

        return new CustomerOrderRef(
            orderId: (string) $order->id,
            orderNumber: $order->order_number,
            placedAt: format_datetime($order->placed_at),
            summary: $summary,
        );
    }
}
