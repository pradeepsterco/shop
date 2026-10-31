<?php

namespace App\Livewire\Storefront;

use App\Orders\Models\Order;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('My Orders')] class extends Component {
    use WithPagination;

    #[Computed]
    public function orders()
    {
        return auth()->user()->orders()
            ->with(['sellerOrders.items', 'sellerOrders.sellerProfile:id,store_slug'])
            ->latest('placed_at')
            ->paginate(10);
    }
}; ?>

<div class="w-full space-y-6">
    <!-- Breadcrumb -->
    <nav class="mb-6 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('products.index') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>Home</a>
        <span>/</span>
        <span class="truncate font-medium text-zinc-800 dark:text-zinc-200">My Orders</span>
    </nav>

    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-3xl">
            My Orders
        </h1>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            View history, track packages, and manage your past purchases.
        </p>
    </div>

    @if ($this->orders->isEmpty())
        <div class="rounded-2xl border border-zinc-200 bg-white p-12 text-center shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="text-lg font-bold text-zinc-900 dark:text-white">You have not placed any orders yet.</h2>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                Explore our catalog to find exciting products and place your first order.
            </p>
            <div class="mt-6">
                <a
                    href="{{ route('products.index') }}"
                    class="inline-flex items-center rounded-xl bg-amber-500 px-6 py-2.5 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-amber-600 transition"
                    wire:navigate
                >
                    Start Shopping
                </a>
            </div>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($this->orders as $order)
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-zinc-100 pb-4 dark:border-zinc-800">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-400">Order</span>
                            <span class="ml-1 text-sm font-bold text-zinc-900 dark:text-white">{{ $order->order_number }}</span>
                            <span class="mx-2 text-zinc-300">&middot;</span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">@datetime($order->placed_at)</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-bold uppercase {{ $order->status->value === 'cancelled' ? 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300' : ($order->status->value === 'completed' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200') }}">
                                {{ $order->status->label() }}
                            </span>
                            <span class="text-base font-extrabold text-zinc-900 dark:text-white">
                                ₹{{ number_format($order->grand_total / 100) }}
                            </span>
                        </div>
                    </div>

                    <!-- Items Preview -->
                    <div class="mt-4 divide-y divide-zinc-50 dark:divide-zinc-800/50">
                        @foreach ($order->sellerOrders as $sellerOrder)
                            @foreach ($sellerOrder->items as $item)
                                <div class="py-3 flex items-center gap-4">
                                    <div class="h-14 w-14 flex-shrink-0 overflow-hidden rounded-lg border border-zinc-200 bg-white p-1 dark:border-zinc-800 dark:bg-zinc-800">
                                        <img
                                            src="{{ $item->image_path ?: asset('storage/products/placeholder.svg') }}"
                                            alt="{{ $item->title }}"
                                            class="h-full w-full object-contain"
                                        />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="truncate text-sm font-semibold text-zinc-900 dark:text-white">
                                            {{ $item->title }}
                                        </h4>
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                            Qty: {{ $item->qty }} &middot; ₹{{ number_format($item->unit_price / 100) }} each &middot; Sold by
                                            @if ($sellerOrder->sellerProfile?->store_slug)
                                                <a href="{{ route('sellers.show', ['slug' => $sellerOrder->sellerProfile->store_slug]) }}" class="hover:underline" wire:navigate>{{ $sellerOrder->seller_store_name }}</a>
                                            @else
                                                {{ $sellerOrder->seller_store_name }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        @endforeach
                    </div>

                    <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 flex justify-end">
                        <a
                            href="{{ route('orders.show', ['order' => $order->order_number]) }}"
                            class="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-amber-600 hover:text-amber-700 dark:text-amber-400"
                            wire:navigate
                        >
                            <span>View Details & Track</span>
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                        </a>
                    </div>
                </div>
            @endforeach

            <div class="pt-4">
                {{ $this->orders->links() }}
            </div>
        </div>
    @endif
</div>

