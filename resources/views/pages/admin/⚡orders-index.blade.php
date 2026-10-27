<?php

namespace App\Livewire\Admin;

use App\Models\SellerProfile;
use App\Orders\Models\Order;
use App\Support\AdminPermissions;
use App\Support\DateFormatter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Orders Management')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $sellerOrderStatus = 'all';

    #[Url]
    public string $sellerId = '';

    #[Url]
    public string $customerId = '';

    #[Url]
    public string $paymentMethod = 'all';

    #[Url]
    public string $datePreset = 'all';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public string $amountMin = '';

    #[Url]
    public string $amountMax = '';

    #[Url]
    public string $sortBy = 'placed_at';

    #[Url]
    public string $sortDir = 'desc';

    public function boot(): void
    {
        Gate::authorize(AdminPermissions::ORDERS_VIEW);
    }

    public function updating($property): void
    {
        if (in_array($property, [
            'search', 'status', 'sellerOrderStatus', 'sellerId', 'customerId',
            'paymentMethod', 'datePreset', 'dateFrom', 'dateTo',
            'amountMin', 'amountMax', 'sortBy', 'sortDir',
        ])) {
            $this->resetPage();
        }
    }

    public function canViewCustomers(): bool
    {
        return auth('admin')->user()?->can(AdminPermissions::CUSTOMERS_VIEW) ?? false;
    }

    public function canViewSellers(): bool
    {
        return auth('admin')->user()?->can(AdminPermissions::SELLERS_VIEW) ?? false;
    }

    public function maskEmail(?string $email): string
    {
        if (blank($email)) {
            return '—';
        }

        if ($this->canViewCustomers()) {
            return $email;
        }

        $atPos = strpos($email, '@');
        if ($atPos === false || $atPos <= 1) {
            return '••••••••';
        }

        return substr($email, 0, 1).'•••'.substr($email, $atPos);
    }

    public function resetFilters(): void
    {
        $this->reset([
            'search',
            'status',
            'sellerOrderStatus',
            'sellerId',
            'customerId',
            'paymentMethod',
            'datePreset',
            'dateFrom',
            'dateTo',
            'amountMin',
            'amountMax',
        ]);
        $this->sortBy = 'placed_at';
        $this->sortDir = 'desc';
        $this->resetPage();
    }

    #[Computed]
    public function sellersList()
    {
        return SellerProfile::orderBy('store_name')->get(['id', 'store_name']);
    }

    protected function applyDateRange(Builder $q): void
    {
        if ($this->datePreset === 'today') {
            [$start, $end] = DateFormatter::todayRange();
            $q->whereBetween('placed_at', [$start, $end]);
        } elseif ($this->datePreset === '7_days') {
            [$start, $end] = DateFormatter::lastDaysRange(7);
            $q->whereBetween('placed_at', [$start, $end]);
        } elseif ($this->datePreset === '30_days') {
            [$start, $end] = DateFormatter::lastDaysRange(30);
            $q->whereBetween('placed_at', [$start, $end]);
        } elseif ($this->datePreset === 'custom') {
            [$start, $end] = DateFormatter::parseCustomRange($this->dateFrom, $this->dateTo);
            if ($start && $end) {
                $q->whereBetween('placed_at', [$start, $end]);
            } elseif ($start) {
                $q->where('placed_at', '>=', $start);
            } elseif ($end) {
                $q->where('placed_at', '<=', $end);
            }
        }
    }

    public function buildFilteredQuery(): Builder
    {
        $term = trim($this->search);
        $canViewCustomers = $this->canViewCustomers();

        return Order::query()
            ->when($this->status !== 'all', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->sellerOrderStatus !== 'all', fn (Builder $q) => $q->whereHas('sellerOrders', fn ($so) => $so->where('status', $this->sellerOrderStatus)))
            ->when(filled($this->sellerId), fn (Builder $q) => $q->whereHas('sellerOrders', fn ($so) => $so->where('seller_profile_id', $this->sellerId)))
            ->when(filled($this->customerId), fn (Builder $q) => $q->where('user_id', $this->customerId))
            ->when($this->paymentMethod !== 'all', fn (Builder $q) => $q->where('payment_method', $this->paymentMethod))
            ->when(filled($this->amountMin) && is_numeric($this->amountMin), fn (Builder $q) => $q->where('grand_total', '>=', (int) round(((float) $this->amountMin) * 100)))
            ->when(filled($this->amountMax) && is_numeric($this->amountMax), fn (Builder $q) => $q->where('grand_total', '<=', (int) round(((float) $this->amountMax) * 100)))
            ->when(true, fn (Builder $q) => $this->applyDateRange($q))
            ->when(filled($term), function (Builder $q) use ($term, $canViewCustomers) {
                $q->where(function (Builder $sub) use ($term, $canViewCustomers) {
                    // Exact / prefix match for order number
                    $sub->where('order_number', 'like', "{$term}%")
                        ->orWhere('order_number', $term);

                    // Contains match for customer name; email/phone only if admin has customers.view
                    $sub->orWhereHas('user', function (Builder $u) use ($term, $canViewCustomers) {
                        $u->where('name', 'like', "%{$term}%");
                        if ($canViewCustomers) {
                            $u->orWhere('email', 'like', "%{$term}%")
                                ->orWhere('phone', 'like', "%{$term}%");
                        }
                    });

                    // Seller store name contains
                    $sub->orWhereHas('sellerOrders', function (Builder $so) use ($term) {
                        $so->where('seller_store_name', 'like', "%{$term}%")
                            // SKU and UPID: exact / prefix match
                            ->orWhereHas('items', function (Builder $it) use ($term) {
                                $it->where('sku', 'like', "{$term}%")
                                    ->orWhere('upid', 'like', "{$term}%");
                            });
                    });
                });
            });
    }

    #[Computed]
    public function summary(): array
    {
        $base = $this->buildFilteredQuery();
        $orderIdsSubquery = (clone $base)->select('orders.id');

        $ordersCount = (clone $base)->count();

        $sellerTotals = DB::table('seller_orders')
            ->whereIn('order_id', $orderIdsSubquery)
            ->selectRaw('COALESCE(SUM(grand_total), 0) as gross_value, COALESCE(SUM(CASE WHEN status = "cancelled" THEN grand_total ELSE 0 END), 0) as cancelled_value')
            ->first();

        $grossValue = (int) ($sellerTotals->gross_value ?? 0);
        $cancelledValue = (int) ($sellerTotals->cancelled_value ?? 0);
        $netValue = max(0, $grossValue - $cancelledValue);

        $itemsSold = (int) DB::table('order_items')
            ->join('seller_orders', 'order_items.seller_order_id', '=', 'seller_orders.id')
            ->whereIn('seller_orders.order_id', (clone $base)->select('orders.id'))
            ->where('seller_orders.status', '!=', 'cancelled')
            ->sum('order_items.qty');

        return [
            'orders_count' => $ordersCount,
            'gross_value' => $grossValue,
            'cancelled_value' => $cancelledValue,
            'net_value' => $netValue,
            'items_sold' => $itemsSold,
        ];
    }

    #[Computed]
    public function orders()
    {
        $sortColumn = in_array($this->sortBy, ['placed_at', 'grand_total']) ? $this->sortBy : 'placed_at';
        $sortDirection = $this->sortDir === 'asc' ? 'asc' : 'desc';

        return $this->buildFilteredQuery()
            ->with(['user', 'sellerOrders.items', 'sellerOrders.sellerProfile'])
            ->orderBy($sortColumn, $sortDirection)
            ->paginate(15);
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-2xl">
                Orders
            </h1>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Inspect platform customer orders, seller fulfillment packages, audit history, and financial metrics.
            </p>
        </div>
    </div>

    <!-- Summary Bar -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block">Orders Count</span>
            <span class="text-xl font-bold text-zinc-900 dark:text-white mt-1 block" data-test="summary-orders-count">
                {{ number_format($this->summary['orders_count']) }}
            </span>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block">Net Order Value</span>
            <span class="text-xl font-bold text-emerald-600 dark:text-emerald-400 mt-1 block" data-test="summary-net-value">
                ₹{{ number_format($this->summary['net_value'] / 100) }}
            </span>
            <span class="text-[10px] text-zinc-400 mt-0.5 block">Gross: ₹{{ number_format($this->summary['gross_value'] / 100) }}</span>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block">Items Sold</span>
            <span class="text-xl font-bold text-indigo-600 dark:text-indigo-400 mt-1 block" data-test="summary-items-sold">
                {{ number_format($this->summary['items_sold']) }}
            </span>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block">Cancelled Value</span>
            <span class="text-xl font-bold text-red-600 dark:text-red-400 mt-1 block" data-test="summary-cancelled-value">
                ₹{{ number_format($this->summary['cancelled_value'] / 100) }}
            </span>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
        <!-- Top Status Tabs & Search Bar -->
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-1.5 border-b lg:border-b-0 pb-2 lg:pb-0 border-zinc-100 dark:border-zinc-800">
                @php
                    $tabs = [
                        'all' => 'All Orders',
                        'placed' => 'Placed',
                        'partially_cancelled' => 'Partially Cancelled',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ];
                @endphp
                @foreach ($tabs as $key => $label)
                    <button
                        type="button"
                        wire:click="$set('status', '{{ $key }}')"
                        class="rounded-lg px-3 py-1.5 text-xs font-semibold transition {{ $status === $key ? 'bg-amber-500 text-white shadow-xs' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="w-full lg:w-96">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search by order #, SKU, UPID, customer or seller..."
                    class="w-full rounded-xl border border-zinc-300 px-3.5 py-2 text-xs shadow-xs focus:border-amber-500 focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                />
            </div>
        </div>

        <!-- Filter Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 pt-3 border-t border-zinc-100 dark:border-zinc-800 text-xs">
            <!-- Seller Filter -->
            <div>
                <label class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400 mb-1">Seller</label>
                <select wire:model.live="sellerId" class="w-full rounded-lg border border-zinc-300 py-1.5 px-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                    <option value="">All Sellers</option>
                    @foreach ($this->sellersList as $s)
                        <option value="{{ $s->id }}">{{ $s->store_name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Seller Order Status -->
            <div>
                <label class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400 mb-1">Package Status</label>
                <select wire:model.live="sellerOrderStatus" class="w-full rounded-lg border border-zinc-300 py-1.5 px-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                    <option value="all">All Packages</option>
                    <option value="placed">Placed</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="packed">Packed</option>
                    <option value="shipped">Shipped</option>
                    <option value="delivered">Delivered</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            <!-- Payment Method -->
            <div>
                <label class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400 mb-1">Payment Method</label>
                <select wire:model.live="paymentMethod" class="w-full rounded-lg border border-zinc-300 py-1.5 px-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                    <option value="all">All Methods</option>
                    <option value="cash_on_delivery">Cash on Delivery</option>
                </select>
            </div>

            <!-- Date Preset -->
            <div>
                <label class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400 mb-1">Date Range</label>
                <select wire:model.live="datePreset" class="w-full rounded-lg border border-zinc-300 py-1.5 px-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                    <option value="all">All Time</option>
                    <option value="today">Today</option>
                    <option value="7_days">Last 7 Days</option>
                    <option value="30_days">Last 30 Days</option>
                    <option value="custom">Custom Range</option>
                </select>
            </div>

            <!-- Amount Min -->
            <div>
                <label class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400 mb-1">Min Amount (₹)</label>
                <input
                    type="number"
                    wire:model.live.debounce.300ms="amountMin"
                    placeholder="Min ₹"
                    class="w-full rounded-lg border border-zinc-300 py-1.5 px-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                />
            </div>

            <!-- Amount Max -->
            <div>
                <label class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400 mb-1">Max Amount (₹)</label>
                <input
                    type="number"
                    wire:model.live.debounce.300ms="amountMax"
                    placeholder="Max ₹"
                    class="w-full rounded-lg border border-zinc-300 py-1.5 px-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                />
            </div>
        </div>

        <!-- Custom Date Range Row & Sorting -->
        <div class="flex flex-wrap items-center justify-between gap-3 pt-3 border-t border-zinc-100 dark:border-zinc-800 text-xs">
            <div class="flex items-center gap-2">
                @if ($datePreset === 'custom')
                    <input type="date" wire:model.live="dateFrom" class="rounded-lg border border-zinc-300 py-1 px-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                    <span class="text-zinc-400">to</span>
                    <input type="date" wire:model.live="dateTo" class="rounded-lg border border-zinc-300 py-1 px-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                @endif
            </div>

            <div class="flex items-center gap-3 ml-auto">
                <span class="text-zinc-500 text-[11px]">Sort:</span>
                <select wire:model.live="sortBy" class="rounded-lg border border-zinc-300 py-1 px-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                    <option value="placed_at">Order Date</option>
                    <option value="grand_total">Order Amount</option>
                </select>
                <select wire:model.live="sortDir" class="rounded-lg border border-zinc-300 py-1 px-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                    <option value="desc">Desc</option>
                    <option value="asc">Asc</option>
                </select>
                <button
                    type="button"
                    wire:click="resetFilters"
                    class="text-xs text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white underline cursor-pointer"
                >
                    Reset
                </button>
            </div>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        @if ($this->orders->isEmpty())
            <div class="p-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
                No orders found matching the selected filters.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500 uppercase tracking-wider font-bold dark:border-zinc-800 dark:bg-zinc-800/40 dark:text-zinc-400">
                        <tr>
                            <th class="py-3 px-4">Order #</th>
                            <th class="py-3 px-4">Date</th>
                            <th class="py-3 px-4">Customer</th>
                            <th class="py-3 px-4">Sellers Involved</th>
                            <th class="py-3 px-4">Items</th>
                            <th class="py-3 px-4">Grand Total</th>
                            <th class="py-3 px-4">Payment</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->orders as $order)
                            @php
                                $uniqueSellers = $order->sellerOrders->pluck('seller_store_name')->unique()->values();
                                $sellerCount = $uniqueSellers->count();
                                $sellerDisplay = $sellerCount <= 2
                                    ? $uniqueSellers->join(', ')
                                    : $uniqueSellers->take(2)->join(', ') . ' +' . ($sellerCount - 2) . ' more';
                                $itemsCount = $order->sellerOrders->flatMap->items->sum('qty');
                                $userAnonymized = $order->user?->isAnonymized() ?? ($order->user?->name === 'Deleted user');
                            @endphp
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition">
                                <td class="py-3 px-4 font-bold text-zinc-900 dark:text-white">
                                    {{ $order->order_number }}
                                </td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-400">
                                    @datetime($order->placed_at)
                                </td>
                                <td class="py-3 px-4 text-zinc-700 dark:text-zinc-300">
                                    @if ($userAnonymized)
                                        <span class="font-medium text-zinc-500 dark:text-zinc-400">Deleted user</span>
                                    @elseif ($this->canViewCustomers() && $order->user)
                                        <a
                                            href="{{ route('admin.customers.show', $order->user_id) }}"
                                            class="font-medium text-amber-600 dark:text-amber-400 hover:underline"
                                            wire:navigate
                                        >
                                            {{ $order->user->name }}
                                        </a>
                                    @else
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ $order->user->name ?? 'Deleted user' }}</span>
                                    @endif
                                    <div class="text-[11px] text-zinc-400">{{ $this->maskEmail($order->user?->email) }}</div>
                                </td>
                                <td class="py-3 px-4 text-zinc-700 dark:text-zinc-300">
                                    {{ $sellerDisplay ?: '—' }}
                                </td>
                                <td class="py-3 px-4 text-zinc-700 dark:text-zinc-300 font-medium">
                                    {{ $itemsCount }} {{ $itemsCount === 1 ? 'unit' : 'units' }}
                                </td>
                                <td class="py-3 px-4 font-bold text-zinc-900 dark:text-white">
                                    ₹{{ number_format($order->grand_total / 100) }}
                                    @if (($order->coupon_discount_total ?? 0) > 0)
                                        <span class="block text-[10px] font-normal text-emerald-600 dark:text-emerald-400">
                                            Coupon: −₹{{ number_format($order->coupon_discount_total / 100) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-400">
                                    <div>{{ $order->payment_method->label() }}</div>
                                    <span class="text-[10px] uppercase font-bold text-zinc-400">{{ $order->payment_status->label() }}</span>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-bold uppercase {{ $order->status->value === 'cancelled' ? 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300' : ($order->status->value === 'completed' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200') }}">
                                        {{ $order->status->label() }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <a
                                        href="{{ route('admin.orders.show', ['order' => $order->id]) }}"
                                        class="rounded-lg border border-zinc-300 px-3 py-1 font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition"
                                        wire:navigate
                                    >
                                        Inspect
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
                {{ $this->orders->links() }}
            </div>
        @endif
    </div>
</div>
