<?php

namespace App\Livewire\Storefront;

use App\Checkout\Services\CheckoutIntentService;
use App\Services\CartService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.storefront')] class extends Component {
    #[Computed]
    public function cartView(): array
    {
        return app(CartService::class)->view();
    }

    public function increaseQty(int $itemId, CartService $cartService): void
    {
        $res = $cartService->increase($itemId, 1);
        if (! $res['success']) {
            session()->flash('cart_error', $res['message'] ?? 'Could not update quantity.');
        }
        $this->dispatch('cart-updated');
    }

    public function decreaseQty(int $itemId, CartService $cartService): void
    {
        $res = $cartService->decrease($itemId, 1);
        if (! $res['success']) {
            session()->flash('cart_error', $res['message'] ?? 'Could not update quantity.');
        }
        $this->dispatch('cart-updated');
    }

    public function removeItem(int $itemId, CartService $cartService): void
    {
        $cartService->remove($itemId);
        $this->dispatch('cart-updated');
        session()->flash('cart_success', 'Item removed from cart.');
    }

    public function acknowledgePriceChanges(CartService $cartService): void
    {
        $cartService->acknowledgePriceChanges();
        session()->flash('cart_success', 'Price changes acknowledged.');
    }

    public function placeOrder(CheckoutIntentService $checkoutService): void
    {
        $view = $this->cartView;

        if (! ($view['can_checkout'] ?? false)) {
            session()->flash('cart_error', 'Please resolve any price changes or out-of-stock items before placing your order.');
            return;
        }

        if (! auth()->check()) {
            session(['url.intended' => route('cart')]);
            $this->redirect(route('login'));
            return;
        }

        // Collect purchasable lines
        $purchasableLines = [];
        foreach ($view['sellers'] as $seller) {
            foreach ($seller['items'] as $item) {
                if ($item['availability'] === 'ok') {
                    $purchasableLines[] = $item;
                }
            }
        }

        if (empty($purchasableLines)) {
            session()->flash('cart_error', 'No purchasable items in cart.');
            return;
        }

        try {
            $intent = $checkoutService->createFromCart(auth()->user(), $purchasableLines);
            $this->redirect(route('checkout.show', ['intent' => $intent->id]));
        } catch (\DomainException $e) {
            session()->flash('cart_error', $e->getMessage());
        }
    }
}; ?>

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 pb-24 sm:pb-8">
    <!-- Breadcrumb -->
    <nav class="mb-6 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('products.index') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>Home</a>
        <span>/</span>
        <span class="truncate font-medium text-zinc-800 dark:text-zinc-200">My Cart</span>
    </nav>

    <!-- Alerts -->
    @if (session()->has('cart_error'))
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/50 dark:text-red-300">
            {{ session('cart_error') }}
        </div>
    @endif
    @if (session()->has('cart_success'))
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300">
            {{ session('cart_success') }}
        </div>
    @endif

    @php
        $view = $this->cartView;
        $hasItems = ($view['lines'] ?? 0) > 0;
    @endphp

    @if (! $hasItems)
        <!-- Empty Cart State -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-12 text-center shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-amber-50 dark:bg-amber-950/50">
                <svg class="h-10 w-10 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" />
                </svg>
            </div>
            <h2 class="mt-4 text-xl font-bold text-zinc-900 dark:text-white">Your cart is empty!</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                Explore our catalog to find exciting products and add them to your cart.
            </p>
            <div class="mt-6">
                <a
                    href="{{ route('products.index') }}"
                    class="inline-flex items-center rounded-xl bg-amber-500 px-6 py-3 text-sm font-semibold text-white shadow-xs hover:bg-amber-600 transition"
                    wire:navigate
                >
                    Shop now
                </a>
            </div>
        </div>
    @else
        <!-- Cart Layout with Items & Price Summary -->
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
            <!-- Left: Items list grouped by seller -->
            <div class="lg:col-span-2 space-y-6">
                @if ($view['has_issues'] ?? false)
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200">
                        <div>
                            <p class="font-semibold">Attention needed before checkout</p>
                            <p class="text-xs text-amber-800 dark:text-amber-300">Some items have updated prices or are out of stock. Please review your items.</p>
                        </div>
                        <button
                            type="button"
                            wire:click="acknowledgePriceChanges"
                            class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 transition"
                        >
                            Acknowledge Changes
                        </button>
                    </div>
                @endif

                @foreach ($view['sellers'] as $seller)
                    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                        <!-- Seller Header -->
                        <div class="border-b border-zinc-100 bg-zinc-50/50 px-6 py-3 text-xs font-medium text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/30 dark:text-zinc-400">
                            Sold by <strong class="text-zinc-900 dark:text-white">{{ $seller['seller_name'] }}</strong>
                        </div>

                        <!-- Seller Item Lines -->
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($seller['items'] as $item)
                                @php
                                    $isOk = $item['availability'] === 'ok';
                                    $unitPrice = (int) $item['unit_price'];
                                    $mrp = (int) max($item['mrp'], $unitPrice);
                                    $discountPct = $mrp > $unitPrice ? (int) round((($mrp - $unitPrice) / $mrp) * 100) : 0;
                                @endphp
                                <div class="p-6 flex flex-col sm:flex-row gap-5 {{ ! $isOk ? 'opacity-70 bg-zinc-50/40 dark:bg-zinc-950/20' : '' }}">
                                    <!-- Thumbnail -->
                                    <div class="h-24 w-24 flex-shrink-0 overflow-hidden rounded-xl border border-zinc-200 bg-white p-2 dark:border-zinc-800 dark:bg-zinc-800">
                                        <img
                                            src="{{ $item['image'] ?: asset('storage/products/placeholder.svg') }}"
                                            alt="{{ $item['name'] }}"
                                            class="h-full w-full object-contain"
                                        />
                                    </div>

                                    <!-- Content Details -->
                                    <div class="flex-1 space-y-2">
                                        <h3 class="font-semibold text-zinc-900 dark:text-white text-base">
                                            {{ $item['name'] }}
                                        </h3>

                                        <!-- Price & MRP -->
                                        <div class="flex items-baseline gap-2.5">
                                            <span class="text-lg font-bold text-zinc-900 dark:text-white">
                                                ₹{{ number_format($unitPrice / 100) }}
                                            </span>
                                            @if ($mrp > $unitPrice)
                                                <span class="text-xs text-zinc-400 line-through">
                                                    ₹{{ number_format($mrp / 100) }}
                                                </span>
                                                <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400">
                                                    {{ $discountPct }}% Off
                                                </span>
                                            @endif
                                        </div>

                                        <!-- Price Change Notice -->
                                        @if ($item['price_changed'])
                                            <div class="text-xs text-amber-700 dark:text-amber-300 font-medium">
                                                Price changed from ₹{{ number_format((int) $item['prev_unit_price'] / 100) }} to ₹{{ number_format($unitPrice / 100) }}
                                            </div>
                                        @endif

                                        <!-- Availability status badge -->
                                        @if (! $isOk)
                                            <div class="text-xs font-semibold text-red-600 dark:text-red-400">
                                                @if ($item['availability'] === 'out_of_stock')
                                                    Out of stock
                                                @elseif ($item['availability'] === 'insufficient')
                                                    Only {{ $item['available_qty'] }} available in stock
                                                @else
                                                    Currently unavailable
                                                @endif
                                            </div>
                                        @endif

                                        <!-- Quantity Stepper & Remove Action -->
                                        <div class="pt-2 flex items-center gap-6">
                                            <div class="inline-flex items-center rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                                                <button
                                                    type="button"
                                                    wire:click="decreaseQty({{ $item['id'] }})"
                                                    wire:loading.attr="disabled"
                                                    {{ $item['qty'] <= 1 ? 'disabled' : '' }}
                                                    class="h-8 w-8 flex items-center justify-center text-zinc-600 hover:bg-zinc-100 disabled:opacity-30 disabled:cursor-not-allowed dark:text-zinc-300 dark:hover:bg-zinc-700 rounded-l-lg transition"
                                                    aria-label="Decrease quantity"
                                                >
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12h-15" />
                                                    </svg>
                                                </button>
                                                <span class="w-10 text-center text-xs font-bold text-zinc-900 dark:text-white">
                                                    {{ $item['qty'] }}
                                                </span>
                                                <button
                                                    type="button"
                                                    wire:click="increaseQty({{ $item['id'] }})"
                                                    wire:loading.attr="disabled"
                                                    {{ $item['qty'] >= 10 || ! $isOk ? 'disabled' : '' }}
                                                    class="h-8 w-8 flex items-center justify-center text-zinc-600 hover:bg-zinc-100 disabled:opacity-30 disabled:cursor-not-allowed dark:text-zinc-300 dark:hover:bg-zinc-700 rounded-r-lg transition"
                                                    aria-label="Increase quantity"
                                                >
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                                    </svg>
                                                </button>
                                            </div>

                                            <button
                                                type="button"
                                                wire:click="removeItem({{ $item['id'] }})"
                                                wire:confirm="Are you sure you want to remove this item from your cart?"
                                                wire:loading.attr="disabled"
                                                class="text-xs font-bold uppercase tracking-wider text-zinc-500 hover:text-red-600 dark:text-zinc-400 dark:hover:text-red-400 transition"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Right: Sticky Price Details Panel -->
            <div class="lg:col-span-1">
                <div class="sticky top-20 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
                    <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 border-b border-zinc-100 dark:border-zinc-800 pb-3">
                        Price Details
                    </h2>

                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between text-zinc-700 dark:text-zinc-300">
                            <span>Price ({{ $view['units'] ?? 0 }} {{ ($view['units'] ?? 0) === 1 ? 'item' : 'items' }})</span>
                            <span>₹{{ number_format(($view['mrp_total'] ?? 0) / 100) }}</span>
                        </div>

                        <div class="flex justify-between text-emerald-600 dark:text-emerald-400">
                            <span>Discount</span>
                            <span>−₹{{ number_format(($view['savings'] ?? 0) / 100) }}</span>
                        </div>

                        <div class="flex justify-between text-zinc-700 dark:text-zinc-300">
                            <span>Delivery Charges</span>
                            <span class="text-emerald-600 dark:text-emerald-400 font-medium">Free</span>
                        </div>

                        <div class="border-t border-dashed border-zinc-200 pt-3 dark:border-zinc-800 flex justify-between text-base font-bold text-zinc-900 dark:text-white">
                            <span>Total Amount</span>
                            <span>₹{{ number_format(($view['subtotal'] ?? 0) / 100) }}</span>
                        </div>
                    </div>

                    @if (($view['savings'] ?? 0) > 0)
                        <div class="rounded-xl bg-emerald-50 p-3 text-xs font-semibold text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                            You will save ₹{{ number_format($view['savings'] / 100) }} on this order
                        </div>
                    @endif

                    <div class="pt-2">
                        @if (! ($view['can_checkout'] ?? false))
                            <button
                                type="button"
                                disabled
                                class="w-full rounded-xl bg-zinc-200 px-6 py-3.5 text-center text-sm font-bold text-zinc-400 cursor-not-allowed dark:bg-zinc-800 dark:text-zinc-500"
                            >
                                Place Order
                            </button>
                            <p class="mt-2 text-center text-xs text-zinc-400">
                                {{ ($view['units'] ?? 0) === 0 ? 'No purchasable items in cart' : 'Resolve issues above to continue' }}
                            </p>
                        @else
                            <button
                                type="button"
                                wire:click="placeOrder"
                                wire:loading.attr="disabled"
                                class="w-full rounded-xl bg-amber-500 px-6 py-3.5 text-center text-sm font-bold text-white shadow-sm hover:bg-amber-600 active:scale-[0.99] transition"
                            >
                                Place Order
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Bottom Checkout Bar -->
        <div class="fixed bottom-0 inset-x-0 z-30 sm:hidden border-t border-zinc-200 bg-white p-4 shadow-lg dark:border-zinc-800 dark:bg-zinc-900 flex items-center justify-between">
            <div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Total Amount</p>
                <p class="text-lg font-extrabold text-zinc-900 dark:text-white">
                    ₹{{ number_format(($view['subtotal'] ?? 0) / 100) }}
                </p>
            </div>
            <div>
                @if ($view['can_checkout'] ?? false)
                    <button
                        type="button"
                        wire:click="placeOrder"
                        class="rounded-xl bg-amber-500 px-6 py-2.5 text-sm font-bold text-white shadow-xs hover:bg-amber-600"
                    >
                        Place Order
                    </button>
                @else
                    <button
                        type="button"
                        disabled
                        class="rounded-xl bg-zinc-200 px-6 py-2.5 text-sm font-bold text-zinc-400 cursor-not-allowed dark:bg-zinc-800 dark:text-zinc-500"
                    >
                        Place Order
                    </button>
                @endif
            </div>
        </div>
    @endif
</div>

