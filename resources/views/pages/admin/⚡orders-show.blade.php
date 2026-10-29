<?php

namespace App\Livewire\Admin;

use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\OrderService;
use App\Returns\Contracts\ReturnRequestServiceInterface;
use App\Returns\Enums\ReturnReason;
use App\Services\AdminActivityLogger;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Order Details')] class extends Component
{
    use WithFileUploads;

    public Order $order;

    public int $customerLifetimeOrdersCount = 0;

    public function boot(): void
    {
        Gate::authorize(AdminPermissions::ORDERS_VIEW);
    }

    public function mount(Order $order): void
    {
        $this->order = $order->load([
            'user',
            'sellerOrders.items',
            'sellerOrders.sellerProfile.user',
            'sellerOrders.statusHistories',
        ]);

        $this->customerLifetimeOrdersCount = $order->user_id
            ? Order::where('user_id', $order->user_id)->count()
            : 0;
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
        if (! $email) {
            return '—';
        }

        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return $email;
        }

        $name = $parts[0];
        $domain = $parts[1];

        $maskedName = mb_substr($name, 0, 1).'•••';

        return $maskedName.'@'.$domain;
    }

    public function maskPhone(?string $phone): string
    {
        if (! $phone) {
            return '—';
        }

        $cleaned = preg_replace('/[^\d+]/', '', $phone) ?? '';
        if (strlen($cleaned) <= 4) {
            return str_repeat('•', strlen($cleaned));
        }

        $visibleDigits = substr($cleaned, -4);
        $maskedCount = strlen($cleaned) - 4;

        return str_repeat('•', $maskedCount).$visibleDigits;
    }

    // Return created by an admin outside the normal rules (e.g. after the window), reason required and logged
    public ?string $overrideSellerOrderId = null;

    /** @var array<int, int|string> */
    public array $overrideUnits = [];

    public string $overrideReturnReason = '';

    public string $overridePreference = 'refund';

    public string $overrideNote = '';

    public array $overridePhotos = [];

    public function openOverrideReturn(string $sellerOrderId): void
    {
        Gate::authorize(AdminPermissions::RETURNS_MANAGE);
        abort_unless($this->order->sellerOrders->contains('id', $sellerOrderId), 404);

        $this->overrideSellerOrderId = $sellerOrderId;
        $this->reset(['overrideUnits', 'overrideReturnReason', 'overrideNote', 'overridePhotos']);
    }

    public function createOverrideReturn(ReturnRequestServiceInterface $returns): void
    {
        Gate::authorize(AdminPermissions::RETURNS_MANAGE);
        $this->resetErrorBag();
        if (! $this->overrideSellerOrderId) {
            return;
        }

        $reason = ReturnReason::tryFrom($this->overrideReturnReason);
        if (! $reason) {
            $this->addError('override', 'Choose the return reason.');

            return;
        }

        try {
            $returns->create(
                userId: (int) $this->order->user_id,
                sellerOrderId: $this->overrideSellerOrderId,
                reason: $reason,
                preference: $this->overridePreference,
                units: array_map('intval', $this->overrideUnits),
                photos: $this->overridePhotos,
                overrideByAdminId: (int) auth()->id(),
                overrideReason: $this->overrideNote,
            );

            session()->flash('order_success', "Return created on the customer's behalf; it is in the returns queue.");
            $this->overrideSellerOrderId = null;
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('override', collect($e->errors())->flatten()->first());
        } catch (\DomainException $e) {
            $this->addError('override', $e->getMessage());
        }
    }

    public function markDelivered(string $sellerOrderId, OrderService $orderService): void
    {
        Gate::authorize(AdminPermissions::ORDERS_MANAGE);

        $sellerOrder = SellerOrder::find($sellerOrderId);
        if (! $sellerOrder) {
            return;
        }

        try {
            $orderService->transitionSellerOrderStatus(
                $sellerOrder,
                SellerOrderStatus::Delivered,
                'admin',
                auth('admin')->id(),
                'Package marked as Delivered by admin'
            );

            AdminActivityLogger::log(
                'orders.deliver',
                $sellerOrder,
                [
                    'order_id' => $this->order->id,
                    'order_number' => $this->order->order_number,
                    'seller_order_id' => $sellerOrder->id,
                    'seller_profile_id' => $sellerOrder->seller_profile_id,
                ]
            );

            session()->flash('order_success', "Package for {$sellerOrder->seller_store_name} marked as Delivered.");
            $this->mount($this->order);
        } catch (\DomainException $e) {
            session()->flash('order_error', $e->getMessage());
        }
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-center gap-2">
        <a href="{{ route('admin.orders.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-semibold text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            <span>Back to Orders</span>
        </a>
    </div>

    <!-- Alerts -->
    @if (session()->has('order_error'))
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs font-semibold text-red-800 dark:border-red-900/50 dark:bg-red-950/50 dark:text-red-300">
            {{ session('order_error') }}
        </div>
    @endif
    @if (session()->has('order_success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300">
            {{ session('order_success') }}
        </div>
    @endif

    <!-- Header Card -->
    <div class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-2xl" data-test="order-number-heading">
                    Order {{ $order->order_number }}
                </h1>
                <span class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-bold uppercase {{ $order->status->value === 'cancelled' ? 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300' : ($order->status->value === 'completed' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200') }}">
                    {{ $order->status->label() }}
                </span>
            </div>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Placed on @datetime($order->placed_at) • Payment: {{ $order->payment_method->label() }} ({{ $order->payment_status->label() }})
            </p>
        </div>

        <div class="text-right">
            <span class="text-xs uppercase font-semibold text-zinc-400">Total Order Value</span>
            <div class="text-2xl font-black text-zinc-900 dark:text-white" data-test="order-grand-total">
                ₹{{ number_format($order->grand_total / 100) }}
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main: Seller Packages / Orders -->
        <div class="lg:col-span-2 space-y-6">
            <h2 class="text-sm font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-300">
                Fulfillment Packages ({{ $order->sellerOrders->count() }})
            </h2>

            @if ($overrideSellerOrderId)
                @php
                    $overridePackage = $order->sellerOrders->firstWhere('id', $overrideSellerOrderId);
                    $overrideLines = app(ReturnRequestServiceInterface::class)->returnableLines($overrideSellerOrderId);
                @endphp
                <form wire:submit.prevent="createOverrideReturn" data-test="override-return-form" class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200 space-y-3">
                    <h3 class="text-sm font-bold">Create a return for {{ $overridePackage?->seller_store_name }} (admin override)</h3>
                    <p>Use this when the customer cannot request it themselves, e.g. after the return window. The reason is required and logged.</p>
                    @foreach ($overrideLines as $itemId => $line)
                        @if ($line['available'] > 0)
                            <label class="flex items-center justify-between gap-2">
                                <span>{{ $line['title'] }} @if ($line['deadline']) (window {{ now()->greaterThan($line['deadline']) ? 'closed' : 'open until' }} @datetime($line['deadline'])) @endif</span>
                                <select wire:model="overrideUnits.{{ $itemId }}" class="rounded-lg border border-amber-300 bg-white p-1.5 dark:bg-zinc-900">
                                    @for ($n = 0; $n <= $line['available']; $n++)
                                        <option value="{{ $n }}">{{ $n }}</option>
                                    @endfor
                                </select>
                            </label>
                        @endif
                    @endforeach
                    <select wire:model="overrideReturnReason" class="w-full rounded-lg border border-amber-300 bg-white p-2 dark:bg-zinc-900">
                        <option value="">Return reason</option>
                        @foreach (ReturnReason::cases() as $reason)
                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                        @endforeach
                    </select>
                    <div class="flex gap-4">
                        <label><input type="radio" wire:model="overridePreference" value="refund" /> Refund</label>
                        <label><input type="radio" wire:model="overridePreference" value="replacement" /> Replacement</label>
                    </div>
                    <input type="file" wire:model="overridePhotos" multiple accept="image/jpeg,image/png,image/webp" class="block w-full" />
                    <input wire:model="overrideNote" type="text" placeholder="Why is this allowed? (required, logged)" data-test="override-note" class="w-full rounded-lg border border-amber-300 bg-white p-2 dark:bg-zinc-900" />
                    @error('override') <p data-test="override-error" class="font-semibold text-red-600">{{ $message }}</p> @enderror
                    <div class="flex gap-2">
                        <button type="submit" wire:confirm="Create this return on the customer's behalf?" data-test="create-override-return-btn" class="rounded-lg bg-amber-600 px-4 py-2 font-bold text-white">Create return</button>
                        <button type="button" wire:click="$set('overrideSellerOrderId', null)" class="rounded-lg border border-amber-400 px-4 py-2">Cancel</button>
                    </div>
                </form>
            @endif

            @foreach ($order->sellerOrders as $sellerOrder)
                <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden" data-test="seller-package-card">
                    <div class="border-b border-zinc-100 bg-zinc-50/70 p-4 dark:border-zinc-800 dark:bg-zinc-800/40 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-xs font-bold text-zinc-900 dark:text-white flex items-center gap-1.5">
                                <span>Seller:</span>
                                @if ($sellerOrder->sellerProfile?->isAnonymized())
                                    <span class="text-zinc-500 italic" data-test="seller-anonymized">Deleted seller</span>
                                @elseif ($this->canViewSellers() && $sellerOrder->seller_profile_id)
                                    <a href="{{ route('admin.sellers.show', $sellerOrder->seller_profile_id) }}" wire:navigate class="text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 hover:underline" data-test="seller-store-link">
                                        {{ $sellerOrder->seller_store_name }}
                                    </a>
                                @else
                                    <span data-test="seller-store-name">{{ $sellerOrder->seller_store_name }}</span>
                                @endif
                            </div>
                            <div class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                GSTIN: {{ $sellerOrder->seller_gstin ?? 'N/A' }} • State: {{ $sellerOrder->seller_pickup_state ?? 'N/A' }}
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            @if ($sellerOrder->courier_name || $sellerOrder->tracking_number)
                                <div class="text-[11px] text-zinc-500 mr-2">
                                    {{ $sellerOrder->courier_name }}: <span class="font-mono font-bold text-zinc-700 dark:text-zinc-300">{{ $sellerOrder->tracking_number }}</span>
                                </div>
                            @endif
                            @can(AdminPermissions::RETURNS_VIEW)
                                @if (\App\Returns\Models\ReturnRequest::where('seller_order_id', $sellerOrder->id)->exists())
                                    <a href="{{ route('admin.returns.index', ['sellerOrder' => $sellerOrder->id, 'status' => 'all']) }}" wire:navigate data-test="order-returns-link" class="text-[11px] font-bold text-indigo-600 underline dark:text-indigo-400">Returns</a>
                                @endif
                            @endcan
                            @can(AdminPermissions::RETURNS_MANAGE)
                                @if (in_array($sellerOrder->status->value, ['delivered', 'partially_delivered', 'partially_refunded'], true))
                                    <button type="button" wire:click="openOverrideReturn('{{ $sellerOrder->id }}')" data-test="open-override-return-btn" class="text-[11px] font-bold text-zinc-600 underline dark:text-zinc-300">Create return</button>
                                @endif
                            @endcan
                            <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-bold uppercase {{ $sellerOrder->status->value === 'cancelled' ? 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300' : ($sellerOrder->status->value === 'delivered' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300') }}">
                                {{ $sellerOrder->status->label() }}
                            </span>
                            @can(AdminPermissions::ORDERS_MANAGE)
                                @if ($sellerOrder->status->canDeliver())
                                    <button
                                        type="button"
                                        wire:click="markDelivered('{{ $sellerOrder->id }}')"
                                        class="rounded-lg bg-emerald-600 px-3 py-1 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-emerald-700 transition"
                                        data-test="mark-delivered-button"
                                    >
                                        Mark Delivered
                                    </button>
                                @endif
                            @endcan
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="border-b border-zinc-100 text-zinc-400 font-bold dark:border-zinc-800">
                                <tr>
                                    <th class="py-2.5 px-4">Item Details</th>
                                    <th class="py-2.5 px-3">HSN</th>
                                    <th class="py-2.5 px-3">GST Rate</th>
                                    <th class="py-2.5 px-3">MRP</th>
                                    <th class="py-2.5 px-3">Unit Price</th>
                                    <th class="py-2.5 px-3">Qty</th>
                                    <th class="py-2.5 px-4 text-right">Line Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($sellerOrder->items as $item)
                                    <tr>
                                        <td class="py-3 px-4">
                                            <div class="flex items-center gap-3">
                                                @if ($item->image_path)
                                                    <img src="{{ \Illuminate\Support\Facades\Storage::url($item->image_path) }}" alt="" class="size-10 rounded-lg object-cover border border-zinc-200 dark:border-zinc-700" />
                                                @else
                                                    <div class="size-10 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-zinc-400">
                                                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                        </svg>
                                                    </div>
                                                @endif
                                                <div>
                                                    <div class="font-bold text-zinc-900 dark:text-white">{{ $item->title }}</div>
                                                    @if ($item->variant_title)
                                                        <div class="text-[11px] text-zinc-500">{{ $item->variant_title }}</div>
                                                    @endif
                                                    <div class="text-[10px] text-zinc-400">SKU: {{ $item->sku }} • UPID: {{ $item->upid }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3 px-3 text-zinc-600 dark:text-zinc-400">{{ $item->hsn_code ?? '—' }}</td>
                                        <td class="py-3 px-3 text-zinc-600 dark:text-zinc-400">
                                            {{ $item->gst_rate }}%
                                            <div class="text-[10px] text-zinc-400">(₹{{ number_format($item->gst_amount / 100, 2) }})</div>
                                        </td>
                                        <td class="py-3 px-3 text-zinc-500 dark:text-zinc-400 line-through">₹{{ number_format($item->mrp / 100) }}</td>
                                        <td class="py-3 px-3 font-semibold text-zinc-900 dark:text-white">₹{{ number_format($item->unit_price / 100) }}</td>
                                        <td class="py-3 px-3 text-zinc-700 dark:text-zinc-300 font-bold">{{ $item->qty }}</td>
                                        <td class="py-3 px-4 text-right font-bold text-zinc-900 dark:text-white">₹{{ number_format($item->line_total / 100) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Package Totals Summary -->
                    @php
                        $packageMrp = $sellerOrder->items->sum(fn ($i) => $i->mrp * $i->qty);
                        $packageGst = $sellerOrder->items->sum('gst_amount');
                    @endphp
                    <div class="border-t border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-800/20">
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-xs">
                            <div>
                                <span class="text-zinc-400 block text-[11px]">MRP Total</span>
                                <span class="font-bold text-zinc-700 dark:text-zinc-300">₹{{ number_format($packageMrp / 100) }}</span>
                            </div>
                            <div>
                                <span class="text-zinc-400 block text-[11px]">Discount Total</span>
                                <span class="font-bold text-emerald-600 dark:text-emerald-400">−₹{{ number_format($sellerOrder->discount_total / 100) }}</span>
                            </div>
                            <div>
                                <span class="text-zinc-400 block text-[11px]">GST Total</span>
                                <span class="font-bold text-zinc-700 dark:text-zinc-300">₹{{ number_format($packageGst / 100, 2) }}</span>
                            </div>
                            <div>
                                <span class="text-zinc-400 block text-[11px]">Package Total</span>
                                <span class="font-bold text-zinc-900 dark:text-white">₹{{ number_format($sellerOrder->grand_total / 100) }}</span>
                            </div>
                            <div>
                                <span class="text-zinc-400 block text-[11px]">COD to Collect</span>
                                <span class="font-bold text-zinc-900 dark:text-white">₹{{ number_format($sellerOrder->grand_total / 100) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Package Status Timeline -->
                    @if ($sellerOrder->statusHistories->isNotEmpty())
                        <div class="border-t border-zinc-100 bg-zinc-50/40 p-4 dark:border-zinc-800 dark:bg-zinc-900/50">
                            <h3 class="text-[11px] font-bold uppercase tracking-wider text-zinc-400 mb-2">Audit Trail</h3>
                            <div class="space-y-2">
                                @foreach ($sellerOrder->statusHistories->sortBy('created_at') as $history)
                                    <div class="flex items-center gap-2 text-[11px]">
                                        <span class="size-1.5 rounded-full bg-zinc-400"></span>
                                        <span class="font-semibold text-zinc-700 dark:text-zinc-300">
                                            {{ $history->from_status ? strtoupper($history->from_status) . ' → ' : '' }}{{ strtoupper($history->to_status) }}
                                        </span>
                                        <span class="text-zinc-400">by {{ $history->changed_by_type }}</span>
                                        @if ($history->reason)
                                            <span class="text-zinc-500 italic">({{ $history->reason }})</span>
                                        @endif
                                        <span class="ml-auto text-zinc-400">@datetime($history->created_at)</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <!-- Sidebar: Customer & Delivery Details & Per-Seller Split -->
        <div class="space-y-6">
            <!-- Customer Card -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-400">Customer Information</h2>
                    @if ($order->user_id && $this->canViewCustomers() && $order->user && ! $order->user->isAnonymized())
                        <a href="{{ route('admin.customers.show', $order->user_id) }}" wire:navigate class="text-xs font-medium text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 hover:underline" data-test="view-customer-profile-link">
                            View Profile &rarr;
                        </a>
                    @endif
                </div>
                <div class="space-y-2 text-xs">
                    <div>
                        <span class="text-zinc-500 dark:text-zinc-400 block text-[11px]">Name</span>
                        @if ($order->user?->isAnonymized())
                            <span class="font-bold text-zinc-500 italic" data-test="customer-anonymized">Deleted user</span>
                        @elseif ($this->canViewCustomers() && $order->user_id && $order->user)
                            <a href="{{ route('admin.customers.show', $order->user_id) }}" wire:navigate class="font-bold text-zinc-900 hover:text-emerald-600 dark:text-white dark:hover:text-emerald-400" data-test="customer-name-link">
                                {{ $order->user->name }}
                            </a>
                        @else
                            <span class="font-bold text-zinc-900 dark:text-white" data-test="customer-name-text">{{ $order->user->name ?? 'Deleted user' }}</span>
                        @endif
                    </div>

                    <div>
                        <span class="text-zinc-500 dark:text-zinc-400 block text-[11px]">Email</span>
                        <span class="text-zinc-700 dark:text-zinc-300" data-test="customer-email-display">
                            @if ($this->canViewCustomers())
                                {{ $order->user->email ?? '—' }}
                            @else
                                {{ $this->maskEmail($order->user?->email) }}
                            @endif
                        </span>
                    </div>

                    <div>
                        <span class="text-zinc-500 dark:text-zinc-400 block text-[11px]">Phone</span>
                        <span class="text-zinc-700 dark:text-zinc-300" data-test="customer-phone-display">
                            @if ($this->canViewCustomers())
                                {{ $order->user->phone ?? '—' }}
                            @else
                                {{ $this->maskPhone($order->user?->phone) }}
                            @endif
                        </span>
                    </div>

                    <div class="pt-2 border-t border-zinc-100 dark:border-zinc-800 flex justify-between items-center text-xs">
                        <span class="text-zinc-500 dark:text-zinc-400">Lifetime Orders</span>
                        <span class="font-bold text-zinc-900 dark:text-white" data-test="customer-lifetime-orders">{{ $customerLifetimeOrdersCount }}</span>
                    </div>
                </div>
            </div>

            <!-- Delivery Address Card -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-400 mb-3">Delivery Address Snapshot</h2>
                @php $addr = $order->delivery_address; @endphp
                <div class="space-y-1 text-xs text-zinc-700 dark:text-zinc-300" data-test="delivery-address-card">
                    <div class="font-bold text-zinc-900 dark:text-white">{{ $addr['name'] ?? '' }}</div>
                    <div>Phone: <span class="font-semibold">{{ $addr['phone'] ?? '' }}</span></div>
                    <div>{{ $addr['address_line'] ?? '' }}</div>
                    @if (!empty($addr['locality']))
                        <div>Locality: {{ $addr['locality'] }}</div>
                    @endif
                    <div>{{ $addr['city'] ?? '' }}, {{ $addr['state'] ?? '' }} - {{ $addr['pincode'] ?? '' }}</div>
                    @if (!empty($addr['landmark']))
                        <div class="text-zinc-500">Landmark: {{ $addr['landmark'] }}</div>
                    @endif
                </div>
            </div>

            <!-- Payment & Totals & Per-Seller Split -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                <div>
                    <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-400 mb-3">Payment & Totals</h2>
                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                            <span>Items MRP</span>
                            <span>₹{{ number_format($order->items_mrp_total / 100) }}</span>
                        </div>
                        <div class="flex justify-between text-emerald-600 dark:text-emerald-400">
                            <span>Catalog Discount</span>
                            <span>−₹{{ number_format($order->discount_total / 100) }}</span>
                        </div>
                        @if (($order->coupon_discount_total ?? 0) > 0)
                            <div class="flex justify-between text-emerald-600 dark:text-emerald-400 font-medium">
                                <span>Coupon ({{ $order->coupon_code }})</span>
                                <span>−₹{{ number_format($order->coupon_discount_total / 100) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                            <span>Delivery Fee</span>
                            <span>{{ $order->delivery_fee === 0 ? 'FREE' : '₹' . number_format($order->delivery_fee / 100) }}</span>
                        </div>
                        <div class="border-t border-zinc-100 pt-2 flex justify-between font-bold text-sm text-zinc-900 dark:text-white dark:border-zinc-800">
                            <span>Grand Total</span>
                            <span data-test="sidebar-grand-total">₹{{ number_format($order->grand_total / 100) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Per-Seller Split -->
                <div class="border-t border-zinc-100 pt-4 dark:border-zinc-800" data-test="per-seller-split-section">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 mb-2.5">Per-Seller Split</h3>
                    <div class="space-y-2 text-xs">
                        @foreach ($order->sellerOrders as $so)
                            @php
                                $pct = $order->grand_total > 0 ? round(($so->grand_total / $order->grand_total) * 100, 1) : 0;
                            @endphp
                            <div class="flex items-center justify-between text-zinc-700 dark:text-zinc-300" data-test="seller-split-row">
                                <span class="truncate max-w-[140px]" title="{{ $so->seller_store_name }}">{{ $so->seller_store_name }}</span>
                                <span class="font-semibold text-zinc-900 dark:text-white flex items-center gap-1.5">
                                    <span data-test="seller-split-amount">₹{{ number_format($so->grand_total / 100) }}</span>
                                    <span class="text-[11px] text-zinc-400 font-normal" data-test="seller-split-pct">({{ $pct }}%)</span>
                                </span>
                            </div>
                        @endforeach
                        <div class="border-t border-dashed border-zinc-200 pt-2 flex justify-between font-bold text-zinc-900 dark:text-white dark:border-zinc-800">
                            <span>Total Package Split</span>
                            <span data-test="split-grand-total">₹{{ number_format($order->sellerOrders->sum('grand_total') / 100) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
