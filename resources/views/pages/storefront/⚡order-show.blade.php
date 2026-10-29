<?php

namespace App\Livewire\Storefront;

use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Orders\Contracts\CustomerRefundServiceInterface;
use App\Orders\Services\OrderService;
use App\Returns\Contracts\ReturnRequestServiceInterface;
use App\Returns\Enums\ReturnReason;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Order Details')] class extends Component {
    use WithFileUploads;

    public string $orderNumber;

    // Return request form (one package at a time)
    public ?string $returningSellerOrderId = null;

    /** @var array<int, int|string> order item id => units */
    public array $returnUnits = [];

    public string $returnReason = '';

    public string $returnPreference = 'refund';

    public string $returnNotes = '';

    public array $returnPhotos = [];

    // Reply to "more information needed"
    /** @var array<string, string> request id => reply */
    public array $replyText = [];

    public array $replyPhotos = [];

    // Refund payout details (one refund at a time)
    public ?string $payoutRefundId = null;

    public string $payoutMethod = 'upi';

    public string $upiId = '';

    public string $bankHolder = '';

    public string $bankAccount = '';

    public string $bankIfsc = '';

    public function startReturn(string $sellerOrderId): void
    {
        $this->authorize('view', $this->order);
        abort_unless($this->order?->sellerOrders->contains('id', $sellerOrderId), 404);

        $this->returningSellerOrderId = $sellerOrderId;
        $this->reset(['returnUnits', 'returnReason', 'returnNotes', 'returnPhotos']);
        $this->returnPreference = 'refund';
    }

    public function cancelReturn(): void
    {
        $this->returningSellerOrderId = null;
    }

    public function submitReturn(ReturnRequestServiceInterface $returns): void
    {
        $this->authorize('view', $this->order);
        $this->resetErrorBag();
        if (! $this->returningSellerOrderId) {
            return;
        }

        $reason = ReturnReason::tryFrom($this->returnReason);
        if (! $reason) {
            $this->addError('returnReason', 'Choose why you are returning.');

            return;
        }

        try {
            // The service checks ownership, the window saved at delivery, units, reasons and photos
            $returns->create(
                userId: (int) auth()->id(),
                sellerOrderId: $this->returningSellerOrderId,
                reason: $reason,
                preference: $this->returnPreference,
                units: array_map('intval', $this->returnUnits),
                photos: $this->returnPhotos,
                notes: $this->returnNotes ?: null,
            );

            session()->flash('order_success', 'Return requested. We will review it shortly.');
            $this->returningSellerOrderId = null;
            $this->reset(['returnUnits', 'returnReason', 'returnNotes', 'returnPhotos']);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError(str_replace('photos', 'returnPhotos', $field), $messages[0]);
            }
        } catch (\DomainException $e) {
            $this->addError('returnForm', $e->getMessage());
        }
    }

    public function submitReply(string $returnRequestId, ReturnRequestServiceInterface $returns): void
    {
        $this->authorize('view', $this->order);
        $this->resetErrorBag();

        try {
            $returns->respond($returnRequestId, (int) auth()->id(), (string) ($this->replyText[$returnRequestId] ?? ''), $this->replyPhotos);
            session()->flash('order_success', 'Thanks, we have your answer.');
            unset($this->replyText[$returnRequestId]);
            $this->replyPhotos = [];
        } catch (ValidationException $e) {
            $this->addError("reply.{$returnRequestId}", collect($e->errors())->flatten()->first());
        } catch (\DomainException $e) {
            $this->addError("reply.{$returnRequestId}", $e->getMessage());
        }
    }

    public function editPayout(string $refundId): void
    {
        $this->authorize('view', $this->order);
        $this->payoutRefundId = $refundId;
        $this->reset(['upiId', 'bankHolder', 'bankAccount', 'bankIfsc']);
        $this->payoutMethod = 'upi';
    }

    public function savePayout(CustomerRefundServiceInterface $refunds): void
    {
        $this->authorize('view', $this->order);
        $this->resetErrorBag();
        if (! $this->payoutRefundId) {
            return;
        }

        try {
            $refunds->updatePayoutDetails($this->payoutRefundId, (int) auth()->id(), $this->payoutMethod === 'upi'
                ? ['payout_method' => 'upi', 'upi_id' => trim($this->upiId)]
                : ['payout_method' => 'bank', 'bank_account_holder' => trim($this->bankHolder), 'bank_account_number' => trim($this->bankAccount), 'bank_ifsc' => trim($this->bankIfsc)]);

            session()->flash('order_success', 'Refund details saved. We will pay your refund to this account.');
            $this->payoutRefundId = null;
            $this->reset(['upiId', 'bankHolder', 'bankAccount', 'bankIfsc']);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('payout', $messages[0]);
            }
        } catch (\DomainException $e) {
            $this->addError('payout', $e->getMessage());
        }
    }

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

                    @if ($sellerOrder->status === \App\Orders\Enums\SellerOrderStatus::LostInTransit)
                        <div data-test="lost-in-transit-notice" class="my-4 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800/60 dark:text-zinc-300">
                            <strong class="font-bold">This package was lost in transit by the courier.</strong>
                            You will not be charged for it.
                        </div>
                    @elseif ($sellerOrder->status === \App\Orders\Enums\SellerOrderStatus::ReturnedToOrigin)
                        <div data-test="rto-notice" class="my-4 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800/60 dark:text-zinc-300">
                            <strong class="font-bold">Returned to seller.</strong>
                            This package could not be delivered and went back to the seller. You will not be charged for it.
                        </div>
                    @endif

                    <!-- Items list -->
                    @php
                        $shipment = app(\App\Shipping\Contracts\ShipmentLookupInterface::class)->getShipmentForSellerOrder($sellerOrder->id);
                        $deliveredItemIds = array_map('intval', $shipment?->partialDeliveryData['delivered_item_ids'] ?? []);
                    @endphp

                    @if ($sellerOrder->status === \App\Orders\Enums\SellerOrderStatus::PartiallyDelivered)
                        <div data-test="partial-delivery-notice" class="my-4 rounded-xl border border-indigo-200 bg-indigo-50 p-3 text-xs text-indigo-900 dark:border-indigo-900/50 dark:bg-indigo-950/40 dark:text-indigo-200">
                            <strong class="font-bold">Partially delivered.</strong>
                            Only some items in this package reached you; each item below shows whether it arrived. You are only charged for delivered items.
                        </div>
                    @endif

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

                            @if ($shipment->status === \App\Shipping\Enums\ShipmentStatus::DeliveryFailed)
                                <div data-test="delivery-failed-alert" class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/60 dark:text-amber-200 flex items-start gap-2.5">
                                    <svg class="size-4 shrink-0 text-amber-600 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                    <div>
                                        <strong class="font-bold">Delivery attempt failed</strong>
                                        <span data-test="ndr-attempt-count" class="ml-1 text-[11px] font-semibold">(attempt {{ $shipment->ndrAttemptCount ?: 1 }})</span>
                                        <p class="mt-0.5">Reason: {{ $shipment->latestNdrReason ?: 'Courier was unable to deliver your package (customer unavailable / address unreachable).' }}</p>
                                        <p class="mt-1 text-[11px] text-amber-800/80 dark:text-amber-300/80">
                                            The courier partner will re-attempt delivery or customer support will contact you.
                                        </p>
                                    </div>
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
                                    @if ($sellerOrder->status === \App\Orders\Enums\SellerOrderStatus::PartiallyDelivered)
                                        @if (in_array((int) $item->id, $deliveredItemIds, true))
                                            <span data-test="item-delivered" class="mt-1 inline-flex rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">Delivered</span>
                                        @else
                                            <span data-test="item-not-delivered" class="mt-1 inline-flex rounded-md bg-zinc-100 px-2 py-0.5 text-[11px] font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Not delivered &mdash; not charged</span>
                                        @endif
                                    @endif
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

                    <!-- Returns & refunds for this package -->
                    @php
                        $returnable = in_array($sellerOrder->status, [\App\Orders\Enums\SellerOrderStatus::Delivered, \App\Orders\Enums\SellerOrderStatus::PartiallyDelivered, \App\Orders\Enums\SellerOrderStatus::PartiallyRefunded], true);
                        $lines = $returnable ? app(ReturnRequestServiceInterface::class)->returnableLines($sellerOrder->id) : [];
                        $openLines = collect($lines)->filter(fn ($line) => $line['available'] > 0 && $line['deadline'] && now()->lessThanOrEqualTo($line['deadline']));
                        $closedLines = collect($lines)->filter(fn ($line) => $line['available'] > 0 && $line['deadline'] && now()->greaterThan($line['deadline']));
                        $packageReturns = \App\Returns\Models\ReturnRequest::with('items')->where('seller_order_id', $sellerOrder->id)->latest()->get();
                        $hasActiveReturn = $packageReturns->contains(fn ($r) => $r->status->isActive());
                        $isReplacementOrder = $sellerOrder->replacement_of_return_request_id !== null;
                        $packageRefunds = \App\Orders\Models\CustomerRefund::where('seller_order_id', $sellerOrder->id)->latest()->get();
                        $shipmentLookup = app(\App\Shipping\Contracts\ShipmentLookupInterface::class);
                    @endphp

                    @if ($returnable || $packageReturns->isNotEmpty() || $packageRefunds->isNotEmpty())
                        <div data-test="returns-section" class="mt-4 border-t border-zinc-100 pt-3 dark:border-zinc-800 space-y-3 text-xs">
                            @if ($openLines->isNotEmpty() && ! $hasActiveReturn && $returningSellerOrderId !== $sellerOrder->id)
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span data-test="return-window" class="text-zinc-600 dark:text-zinc-300">Returns open until <strong>@datetime($openLines->pluck('deadline')->max())</strong></span>
                                    <button type="button" wire:click="startReturn('{{ $sellerOrder->id }}')" data-test="start-return-btn" class="rounded-lg border border-zinc-300 px-3 py-1.5 font-bold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200">Return items</button>
                                </div>
                            @elseif ($openLines->isEmpty() && $closedLines->isNotEmpty())
                                <p data-test="return-window-closed" class="text-zinc-500">The return window closed on @datetime($closedLines->pluck('deadline')->max()).</p>
                            @endif

                            @if ($returningSellerOrderId === $sellerOrder->id)
                                <form wire:submit.prevent="submitReturn" data-test="return-form" class="space-y-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                                    @foreach ($lines as $itemId => $line)
                                        @if ($line['available'] > 0)
                                            <label class="flex items-center justify-between gap-2">
                                                <span>{{ $line['title'] }}
                                                    @unless ($line['returnable'])
                                                        <span class="text-amber-700">(only if damaged, defective, wrong or missing)</span>
                                                    @endunless
                                                </span>
                                                <select wire:model="returnUnits.{{ $itemId }}" data-test="return-units-{{ $itemId }}" class="rounded-lg border border-zinc-300 bg-white p-1.5 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                                                    @for ($n = 0; $n <= $line['available']; $n++)
                                                        <option value="{{ $n }}">{{ $n }}</option>
                                                    @endfor
                                                </select>
                                            </label>
                                        @endif
                                    @endforeach

                                    <select wire:model="returnReason" data-test="return-reason" class="w-full rounded-lg border border-zinc-300 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                                        <option value="">Why are you returning?</option>
                                        @foreach (ReturnReason::cases() as $reason)
                                            <option value="{{ $reason->value }}">{{ $reason->label() }}{{ $reason->requiresPhotos() ? ' (photos needed)' : '' }}</option>
                                        @endforeach
                                    </select>
                                    @error('returnReason') <p class="text-red-600">{{ $message }}</p> @enderror

                                    <label class="block">Photos (JPG, PNG or WebP; up to 5; needed for damaged, defective, wrong or missing items)
                                        <input type="file" wire:model="returnPhotos" multiple accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full" />
                                    </label>
                                    @error('returnPhotos') <p class="text-red-600">{{ $message }}</p> @enderror
                                    @error('returnPhotos.*') <p class="text-red-600">{{ $message }}</p> @enderror

                                    @unless ($isReplacementOrder)
                                        <div class="flex gap-4" data-test="return-preference">
                                            <label><input type="radio" wire:model="returnPreference" value="refund" /> Refund</label>
                                            <label><input type="radio" wire:model="returnPreference" value="replacement" /> Replacement</label>
                                        </div>
                                    @endunless

                                    <textarea wire:model="returnNotes" rows="2" placeholder="Anything we should know?" class="w-full rounded-lg border border-zinc-300 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"></textarea>
                                    @error('returnForm') <p data-test="return-error" class="font-semibold text-red-600">{{ $message }}</p> @enderror

                                    <div class="flex gap-2">
                                        <button type="submit" data-test="submit-return-btn" class="rounded-lg bg-zinc-900 px-4 py-2 font-bold text-white dark:bg-white dark:text-zinc-900">Request return</button>
                                        <button type="button" wire:click="cancelReturn" class="rounded-lg border border-zinc-300 px-4 py-2 dark:border-zinc-700">Cancel</button>
                                    </div>
                                </form>
                            @endif

                            @foreach ($packageReturns as $return)
                                <div data-test="customer-return-{{ $return->id }}" class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60 space-y-2">
                                    <div class="flex justify-between gap-2">
                                        <span><strong>Return:</strong> {{ $return->reason->label() }} &middot;
                                            @foreach ($return->items as $returnLine)
                                                {{ $sellerOrder->items->firstWhere('id', $returnLine->order_item_id)?->title }} &times; {{ $returnLine->units }}@if (! $loop->last), @endif
                                            @endforeach
                                        </span>
                                        <span data-test="customer-return-status" class="font-bold">{{ $return->status->label() }}</span>
                                    </div>

                                    @if ($return->rejection_reason)<p class="text-red-600">Rejected: {{ $return->rejection_reason }}</p>@endif
                                    @if ($return->closed_reason)<p class="text-zinc-500">Closed: {{ $return->closed_reason }}. You can request again while the window is open.</p>@endif

                                    @if ($return->status === \App\Returns\Enums\ReturnRequestStatus::InfoRequested)
                                        <div class="rounded-lg border border-amber-300 bg-amber-50 p-2 dark:border-amber-800 dark:bg-amber-950/40">
                                            <p><strong>We need more information:</strong> {{ $return->info_request_message }}</p>
                                            <textarea wire:model="replyText.{{ $return->id }}" rows="2" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"></textarea>
                                            <input type="file" wire:model="replyPhotos" multiple accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full" />
                                            @error("reply.{$return->id}") <p class="text-red-600">{{ $message }}</p> @enderror
                                            <button type="button" wire:click="submitReply('{{ $return->id }}')" data-test="submit-reply-btn" class="mt-1 rounded-lg bg-zinc-900 px-3 py-1.5 font-bold text-white dark:bg-white dark:text-zinc-900">Send answer</button>
                                        </div>
                                    @endif

                                    {{-- Return pickup timeline: the courier's own words, place and time (IST) --}}
                                    @php $pickup = $return->reverse_shipment_id ? $shipmentLookup->getShipmentById($return->reverse_shipment_id) : null; @endphp
                                    @if ($pickup && $pickup->events !== [])
                                        <ol data-test="return-pickup-timeline" class="space-y-1 border-l border-zinc-300 pl-3 dark:border-zinc-700">
                                            @foreach ($pickup->events as $event)
                                                <li>
                                                    <strong>{{ $event->providerStatus }}</strong>
                                                    @if ($event->location) &middot; {{ $event->location }} @endif
                                                    &middot; <span class="text-zinc-500">@datetime($event->occurredAt)</span>
                                                    @if ($event->description && $event->description !== $event->providerStatus)<p class="text-zinc-500">{{ $event->description }}</p>@endif
                                                </li>
                                            @endforeach
                                        </ol>
                                    @endif

                                    @if ($return->replacement_seller_order_id)
                                        @php $replacementOrder = \App\Orders\Models\SellerOrder::with('order')->find($return->replacement_seller_order_id); @endphp
                                        @if ($replacementOrder)
                                            <a href="{{ route('orders.show', ['order' => $replacementOrder->order->order_number]) }}" wire:navigate data-test="replacement-link" class="font-bold text-indigo-600 underline dark:text-indigo-400">Your replacement: order {{ $replacementOrder->order->order_number }} &rarr;</a>
                                        @endif
                                    @endif
                                </div>
                            @endforeach

                            @foreach ($packageRefunds as $refund)
                                <div data-test="customer-refund-{{ $refund->id }}" class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700 space-y-1">
                                    <div class="flex justify-between">
                                        <span>Refund ₹{{ number_format($refund->amount_paise / 100, 2) }}</span>
                                        <span data-test="refund-status" class="font-bold">{{ $refund->status->label() }}</span>
                                    </div>
                                    @if ($refund->status === \App\Orders\Enums\RefundStatus::Paid)
                                        <p data-test="refund-paid">Paid @datetime($refund->paid_at) &middot; reference ••••{{ substr((string) $refund->utr, -4) }}</p>
                                    @elseif ($refund->status === \App\Orders\Enums\RefundStatus::Failed)
                                        <p class="text-red-600">Payout failed: {{ $refund->failure_reason }}. Please update your details.</p>
                                    @endif

                                    @if ($refund->status->isOpen())
                                        <p class="text-zinc-500">Paid to: {{ $refund->payoutDestination() }}</p>
                                        @if ($payoutRefundId === $refund->id)
                                            <form wire:submit.prevent="savePayout" data-test="payout-form" class="space-y-2">
                                                <div class="flex gap-4">
                                                    <label><input type="radio" wire:model.live="payoutMethod" value="upi" /> UPI</label>
                                                    <label><input type="radio" wire:model.live="payoutMethod" value="bank" /> Bank account</label>
                                                </div>
                                                @if ($payoutMethod === 'upi')
                                                    <input wire:model="upiId" type="text" placeholder="name@bank" class="w-full rounded-lg border border-zinc-300 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                                                @else
                                                    <input wire:model="bankHolder" type="text" placeholder="Account holder name" class="w-full rounded-lg border border-zinc-300 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                                                    <input wire:model="bankAccount" type="text" inputmode="numeric" placeholder="Account number" class="w-full rounded-lg border border-zinc-300 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                                                    <input wire:model="bankIfsc" type="text" placeholder="IFSC (e.g. HDFC0001234)" class="w-full rounded-lg border border-zinc-300 bg-white p-2 uppercase dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                                                @endif
                                                @error('payout') <p data-test="payout-error" class="text-red-600">{{ $message }}</p> @enderror
                                                <button type="submit" data-test="save-payout-btn" class="rounded-lg bg-zinc-900 px-3 py-1.5 font-bold text-white dark:bg-white dark:text-zinc-900">Save refund details</button>
                                            </form>
                                        @else
                                            <button type="button" wire:click="editPayout('{{ $refund->id }}')" data-test="edit-payout-btn" class="font-bold text-indigo-600 underline dark:text-indigo-400">
                                                {{ $refund->hasPayoutDetails() ? 'Update refund details' : 'Add UPI or bank details to receive your refund' }}
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

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

                    @if (($order->coupon_discount_total ?? 0) > 0)
                        <div class="flex justify-between text-emerald-600 dark:text-emerald-400 font-medium">
                            <span>Coupon Discount ({{ $order->coupon_code }})</span>
                            <span>−₹{{ number_format($order->coupon_discount_total / 100) }}</span>
                        </div>
                    @endif

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

