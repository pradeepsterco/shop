<?php

namespace App\Livewire\Storefront;

use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\OrderService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Order Details')] class extends Component {
    public string $orderNumber;

    public ?string $cancellingSellerOrderId = null;

    public string $cancelReason = '';

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
        return Order::with(['sellerOrders.items', 'sellerOrders.statusHistories'])
            ->where('order_number', $this->orderNumber)
            ->first();
    }

    public function promptCancel(string $sellerOrderId): void
    {
        $this->authorize('cancel', $this->order);

        $this->cancellingSellerOrderId = $sellerOrderId;
        $this->cancelReason = 'Order created by mistake';
    }

    public function confirmCancel(OrderService $orderService): void
    {
        $this->authorize('cancel', $this->order);

        if (! $this->cancellingSellerOrderId) {
            return;
        }

        /** @var SellerOrder|null $sellerOrder */
        $sellerOrder = SellerOrder::where('id', $this->cancellingSellerOrderId)
            ->where('order_id', $this->order?->id)
            ->first();

        if (! $sellerOrder) {
            session()->flash('order_error', 'Order not found.');
            $this->cancellingSellerOrderId = null;

            return;
        }

        try {
            $orderService->cancelSellerOrder(
                $sellerOrder,
                'customer',
                auth()->id(),
                $this->cancelReason ?: 'Cancelled by customer'
            );

            session()->flash('order_success', 'Order cancelled successfully. Restocked items.');
            $this->cancellingSellerOrderId = null;
            unset($this->order);
        } catch (\DomainException $e) {
            session()->flash('order_error', $e->getMessage());
        }
    }
}; ?>

<div class="w-full space-y-6">
    <!-- Breadcrumb -->
    <nav class="mb-6 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('products.index') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>Home</a>
        <span>/</span>
        <a href="{{ route('orders.index') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>My Orders</a>
        <span>/</span>
        <span class="truncate font-medium text-zinc-800 dark:text-zinc-200">{{ $this->orderNumber }}</span>
    </nav>

    <!-- Alerts -->
    @if (session()->has('order_error'))
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/50 dark:text-red-300">
            {{ session('order_error') }}
        </div>
    @endif
    @if (session()->has('order_success'))
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300">
            {{ session('order_success') }}
        </div>
    @endif

    @php $order = $this->order; @endphp

    <!-- Header Section -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-3xl">
                Order {{ $order->order_number }}
            </h1>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Placed on @datetime($order->placed_at)
            </p>
        </div>
        <div>
            <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-bold uppercase {{ $order->status->value === 'cancelled' ? 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300' : ($order->status->value === 'completed' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-amber-100 text-amber-900 dark:bg-amber-950/60 dark:text-amber-200') }}">
                Status: {{ $order->status->label() }}
            </span>
        </div>
    </div>

    <!-- Cancellation Confirmation Modal / Form -->
    @if ($this->cancellingSellerOrderId)
        <div class="mb-6 rounded-2xl border border-red-200 bg-red-50/50 p-5 dark:border-red-900/60 dark:bg-red-950/30">
            <h3 class="text-sm font-bold text-red-900 dark:text-red-300">Cancel Package / Order</h3>
            <p class="mt-1 text-xs text-red-700 dark:text-red-400">
                Are you sure you want to cancel this package? This will release reserved inventory.
            </p>
            <div class="mt-3">
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Reason for cancellation:</label>
                <input
                    type="text"
                    wire:model="cancelReason"
                    class="w-full sm:w-96 rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                />
            </div>
            <div class="mt-4 flex items-center gap-3">
                <button
                    type="button"
                    wire:click="confirmCancel"
                    class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-red-700 transition"
                >
                    Confirm Cancellation
                </button>
                <button
                    type="button"
                    wire:click="$set('cancellingSellerOrderId', null)"
                    class="text-xs font-semibold text-zinc-500 hover:text-zinc-800 dark:text-zinc-400"
                >
                    Nevermind
                </button>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
        <!-- Left: Seller Orders & Items -->
        <div class="lg:col-span-2 space-y-6">
            @foreach ($order->sellerOrders as $sellerOrder)
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <!-- Seller Order Header -->
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-zinc-100 pb-4 dark:border-zinc-800">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-400">Package from</span>
                            <span class="ml-1 text-sm font-bold text-zinc-900 dark:text-white">{{ $sellerOrder->seller_store_name }}</span>
                            @if ($sellerOrder->seller_pickup_state)
                                <span class="text-xs text-zinc-400">({{ $sellerOrder->seller_pickup_state }})</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-bold uppercase {{ $sellerOrder->status->value === 'cancelled' ? 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300' : 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300' }}">
                                {{ $sellerOrder->status->label() }}
                            </span>
                            @if ($sellerOrder->status->canCancelByCustomer() && ! $this->cancellingSellerOrderId)
                                <button
                                    type="button"
                                    wire:click="promptCancel('{{ $sellerOrder->id }}')"
                                    class="text-xs font-bold uppercase tracking-wider text-red-600 hover:text-red-700 dark:text-red-400"
                                >
                                    Cancel Package
                                </button>
                            @endif
                        </div>
                    </div>

                    <!-- Items list -->
                    @php
                        $shipment = app(\App\Shipping\Contracts\ShipmentLookupInterface::class)->getShipmentForSellerOrder($sellerOrder->id);
                    @endphp

                    @if ($shipment)
                        <div class="my-4 rounded-xl border border-indigo-100 bg-indigo-50/50 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/20">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold uppercase tracking-wider text-indigo-900 dark:text-indigo-300">Courier: {{ strtoupper($shipment->provider) }}</span>
                                    <span class="text-zinc-300 dark:text-zinc-700">&bull;</span>
                                    <span class="text-xs text-zinc-600 dark:text-zinc-400">AWB: <code class="font-mono font-bold">{{ $shipment->awb }}</code></span>
                                </div>
                                <span class="inline-flex items-center rounded-lg px-2.5 py-0.5 text-xs font-bold {{ $shipment->status->badgeClasses() }}">
                                    {{ $shipment->status->label() }}
                                </span>
                            </div>

                            @if ($shipment->publicTrackingUrl)
                                <div class="mt-2">
                                    <a href="{{ $shipment->publicTrackingUrl }}" target="_blank" class="text-xs font-bold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 underline inline-flex items-center gap-1">
                                        Track delivery on {{ strtoupper($shipment->provider) }} &rarr;
                                    </a>
                                </div>
                            @endif

                            @if (! empty($shipment->events))
                                <div class="mt-3 border-t border-indigo-100 dark:border-indigo-900/40 pt-2">
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-400">Latest Tracking Update:</span>
                                    @php $latestEvent = $shipment->events[0] ?? null; @endphp
                                    @if ($latestEvent)
                                        <p class="text-xs text-zinc-700 dark:text-zinc-300 mt-0.5">
                                            <strong>{{ $latestEvent->providerStatus }}</strong>
                                            @if ($latestEvent->location) &mdash; {{ $latestEvent->location }} @endif
                                            <span class="text-[11px] text-zinc-400">(@datetime($latestEvent->occurredAt))</span>
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($sellerOrder->items as $item)
                            <div class="py-4 flex gap-4">
                                <div class="h-16 w-16 flex-shrink-0 overflow-hidden rounded-xl border border-zinc-200 bg-white p-1.5 dark:border-zinc-800 dark:bg-zinc-800">
                                    <img
                                        src="{{ $item->image_path ?: asset('storage/products/placeholder.svg') }}"
                                        alt="{{ $item->title }}"
                                        class="h-full w-full object-contain"
                                    />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="font-semibold text-zinc-900 dark:text-white text-sm">
                                        {{ $item->title }}
                                    </h4>
                                    @if ($item->variant_title)
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $item->variant_title }}
                                        </p>
                                    @endif
                                    <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">
                                        Qty: <strong>{{ $item->qty }}</strong> &middot; Price: <strong>₹{{ number_format($item->unit_price / 100) }}</strong>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <span class="text-sm font-bold text-zinc-900 dark:text-white">
                                        ₹{{ number_format($item->line_total / 100) }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <!-- Package Total Footer -->
                    <div class="mt-4 border-t border-zinc-100 pt-3 dark:border-zinc-800 flex justify-between items-center text-xs">
                        <span class="text-zinc-500 dark:text-zinc-400">
                            Package Total ({{ $sellerOrder->items->sum('qty') }} {{ $sellerOrder->items->sum('qty') === 1 ? 'unit' : 'units' }}):
                        </span>
                        <span class="font-extrabold text-zinc-900 dark:text-white text-sm">
                            ₹{{ number_format($sellerOrder->grand_total / 100) }}
                        </span>
                    </div>

                    <!-- Status History / Timeline Notes -->
                    @if ($sellerOrder->statusHistories->isNotEmpty())
                        <div class="mt-4 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">Activity Log</span>
                            <ul class="mt-1 space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                @foreach ($sellerOrder->statusHistories as $log)
                                    <li>
                                        &bull; @datetime($log->created_at) — Status changed to <strong class="text-zinc-700 dark:text-zinc-300">{{ ucfirst($log->to_status) }}</strong>
                                        @if ($log->reason)
                                            <span class="italic">({{ $log->reason }})</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <!-- Right: Address & Price Breakdown -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Delivery Address Card -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    Delivery Address
                </h3>
                <div class="mt-3 text-sm text-zinc-700 dark:text-zinc-300 space-y-1">
                    <p class="font-bold text-zinc-900 dark:text-white">{{ $order->delivery_address['name'] ?? 'Recipient' }}</p>
                    <p class="text-xs text-zinc-600 dark:text-zinc-400">{{ $order->delivery_address['address_line'] ?? '' }}</p>
                    <p class="text-xs text-zinc-600 dark:text-zinc-400">{{ $order->delivery_address['locality'] ?? '' }}</p>
                    <p class="text-xs text-zinc-600 dark:text-zinc-400">{{ $order->delivery_address['city'] ?? '' }}, {{ $order->delivery_state }} - {{ $order->delivery_address['pincode'] ?? '' }}</p>
                    <p class="text-xs font-medium text-zinc-800 dark:text-zinc-200 pt-1">Phone: {{ $order->delivery_address['phone'] ?? '' }}</p>
                </div>
            </div>

            <!-- Price Breakdown Card -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    Price Details
                </h3>

                <div class="space-y-2.5 text-sm">
                    <div class="flex justify-between text-zinc-700 dark:text-zinc-300">
                        <span>Items MRP Total</span>
                        <span>₹{{ number_format($order->items_mrp_total / 100) }}</span>
                    </div>

                    <div class="flex justify-between text-emerald-600 dark:text-emerald-400">
                        <span>Discount on MRP</span>
                        <span>−₹{{ number_format($order->discount_total / 100) }}</span>
                    </div>

                    <div class="flex justify-between text-zinc-700 dark:text-zinc-300">
                        <span>Delivery Fee</span>
                        <span class="text-emerald-600 dark:text-emerald-400 font-medium">Free</span>
                    </div>

                    <div class="border-t border-dashed border-zinc-200 pt-3 dark:border-zinc-800 flex justify-between text-base font-bold text-zinc-900 dark:text-white">
                        <span>Total Amount</span>
                        <span>₹{{ number_format($order->grand_total / 100) }}</span>
                    </div>
                </div>

                <div class="border-t border-zinc-100 pt-3 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400 flex items-center justify-between">
                    <span>Payment Method:</span>
                    <strong class="text-zinc-800 dark:text-zinc-200 uppercase">Cash on Delivery</strong>
                </div>
            </div>
        </div>
    </div>
</div>

