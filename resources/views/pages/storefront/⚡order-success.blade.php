<?php

namespace App\Livewire\Storefront;

use App\Orders\Models\Order;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.storefront')] class extends Component {
    public string $orderNumber;

    public function mount(string $order): void
    {
        $this->orderNumber = $order;

        $orderModel = $this->order;
        if (! $orderModel) {
            abort(404, 'Order not found.');
        }

        $this->authorize('view', $orderModel);
    }

    #[Computed]
    public function order(): ?Order
    {
        return Order::with(['sellerOrders.items'])->where('order_number', $this->orderNumber)->first();
    }
}; ?>

<div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
    @php $order = $this->order; @endphp

    <!-- Success Celebration Card -->
    <div class="rounded-2xl border border-emerald-200 bg-white p-8 text-center shadow-xs dark:border-emerald-900/60 dark:bg-zinc-900">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
        </div>

        <h1 class="mt-4 text-2xl font-extrabold tracking-tight text-zinc-900 dark:text-white sm:text-3xl">
            Order Placed Successfully!
        </h1>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
            Thank you for shopping with us. Your order <strong class="text-zinc-900 dark:text-white">{{ $order->order_number }}</strong> has been confirmed.
        </p>

        <!-- Quick Summary Details -->
        <div class="mt-6 rounded-xl border border-zinc-100 bg-zinc-50 p-4 text-left text-xs text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/40 dark:text-zinc-300 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <span class="block text-zinc-400 uppercase font-bold tracking-wider">Payment Method</span>
                <span class="font-semibold text-zinc-800 dark:text-zinc-200 text-sm">Cash on Delivery</span>
            </div>
            <div>
                <span class="block text-zinc-400 uppercase font-bold tracking-wider">Total Amount</span>
                <span class="font-bold text-zinc-900 dark:text-white text-sm">₹{{ number_format($order->grand_total / 100) }}</span>
            </div>
            <div>
                <span class="block text-zinc-400 uppercase font-bold tracking-wider">Delivery To</span>
                <span class="font-semibold text-zinc-800 dark:text-zinc-200 text-sm truncate block">{{ $order->delivery_address['name'] ?? 'Recipient' }} ({{ $order->delivery_state }})</span>
            </div>
        </div>

        <!-- Action Links -->
        <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
            <a
                href="{{ route('orders.show', ['order' => $order->order_number]) }}"
                class="w-full sm:w-auto rounded-xl bg-amber-500 px-6 py-3 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-amber-600 transition"
                wire:navigate
            >
                View Order Details
            </a>
            <a
                href="{{ route('products.index') }}"
                class="w-full sm:w-auto rounded-xl border border-zinc-200 bg-white px-6 py-3 text-xs font-bold uppercase tracking-wider text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 transition"
                wire:navigate
            >
                Continue Shopping
            </a>
        </div>
    </div>
</div>

