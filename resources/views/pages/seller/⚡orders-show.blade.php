<?php

namespace App\Livewire\Seller;

use App\Orders\Contracts\SellerOrderShippingDetailsLookupInterface;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\OrderService;
use App\Returns\Contracts\ReturnsLookupInterface;
use App\Returns\Contracts\RtoIntakeServiceInterface;
use App\Sellers\Contracts\SellerPickupAddressLookupInterface;
use App\Shipping\Contracts\CarrierManagerInterface;
use App\Shipping\Contracts\ShipmentLookupInterface;
use App\Shipping\Contracts\ShipmentServiceInterface;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.seller')] #[Title('Order Details')] class extends Component {
    use WithFileUploads;

    public string $sellerOrderId;

    public bool $showCancelModal = false;

    public string $cancelReason = '';

    public bool $showShipModal = false;

    public string $courierName = '';

    public string $trackingNumber = '';

    public bool $showReadyToShipModal = false;

    public int $boxWeightGrams = 500;

    public int $boxLengthCm = 15;

    public int $boxWidthCm = 10;

    public int $boxHeightCm = 5;

    public ?string $boxPickupDate = null;

    // RTO inspection fields
    public string $rtoCondition = 'intact';

    public string $rtoNotes = '';

    public array $rtoPhotos = [];

    // Customer return condition report
    public string $returnCondition = 'intact';

    public string $returnNotes = '';

    public array $returnPhotos = [];

    public function mount(string $sellerOrder): void
    {
        $this->sellerOrderId = $sellerOrder;

        $orderModel = $this->order;
        if (! $orderModel) {
            abort(404, 'Seller order not found.');
        }

        $this->authorize('view', $orderModel);
    }

    #[Computed]
    public function order(): ?SellerOrder
    {
        return SellerOrder::with(['order', 'items', 'statusHistories'])
            ->find($this->sellerOrderId);
    }

    public function confirmOrder(OrderService $orderService): void
    {
        $order = $this->order;
        if (! $order) {
            return;
        }

        $this->authorize('update', $order);

        try {
            $orderService->transitionSellerOrderStatus(
                $order,
                SellerOrderStatus::Confirmed,
                'seller',
                auth()->id(),
                'Order confirmed by seller'
            );

            session()->flash('order_success', 'Order marked as Confirmed.');
            unset($this->order);
        } catch (\DomainException $e) {
            session()->flash('order_error', $e->getMessage());
        }
    }

    public function markPacked(OrderService $orderService): void
    {
        $order = $this->order;
        if (! $order) {
            return;
        }

        $this->authorize('update', $order);

        try {
            $orderService->transitionSellerOrderStatus(
                $order,
                SellerOrderStatus::Packed,
                'seller',
                auth()->id(),
                'Order marked as Packed'
            );

            session()->flash('order_success', 'Order marked as Packed and ready for dispatch.');
            unset($this->order);
        } catch (\DomainException $e) {
            session()->flash('order_error', $e->getMessage());
        }
    }

    public function confirmShip(OrderService $orderService): void
    {
        $order = $this->order;
        if (! $order) {
            return;
        }

        $this->authorize('update', $order);

        $this->validate([
            'courierName' => ['required', 'string', 'min:2', 'max:100'],
            'trackingNumber' => ['required', 'string', 'min:3', 'max:100'],
        ]);

        try {
            $orderService->transitionSellerOrderStatus(
                $order,
                SellerOrderStatus::Shipped,
                'seller',
                auth()->id(),
                "Order shipped via {$this->courierName} (Tracking: {$this->trackingNumber})",
                $this->courierName,
                $this->trackingNumber
            );

            session()->flash('order_success', 'Order marked as Shipped.');
            $this->showShipModal = false;
            $this->courierName = '';
            $this->trackingNumber = '';
            unset($this->order);
        } catch (\DomainException $e) {
            session()->flash('order_error', $e->getMessage());
        }
    }

    public function confirmCancel(OrderService $orderService): void
    {
        $order = $this->order;
        if (! $order) {
            return;
        }

        $this->authorize('cancel', $order);

        try {
            $orderService->cancelSellerOrder(
                $order,
                'seller',
                auth()->id(),
                $this->cancelReason ?: 'Cancelled by seller due to inventory constraint'
            );

            session()->flash('order_success', 'Order cancelled and stock restocked.');
            $this->showCancelModal = false;
            unset($this->order);
        } catch (\DomainException $e) {
            session()->flash('order_error', $e->getMessage());
        }
    }

    public function openReadyToShipModal(SellerOrderShippingDetailsLookupInterface $lookup): void
    {
        $details = $lookup->getShippingDetails($this->sellerOrderId);
        if ($details) {
            $this->boxWeightGrams = $details->estimatedTotalWeightGrams;
            $maxL = 15;
            $maxW = 10;
            $maxH = 5;
            foreach ($details->items as $item) {
                $maxL = max($maxL, $item->lengthCm);
                $maxW = max($maxW, $item->widthCm);
                $maxH = max($maxH, $item->heightCm);
            }
            $this->boxLengthCm = $maxL;
            $this->boxWidthCm = $maxW;
            $this->boxHeightCm = $maxH;
        }

        $this->boxPickupDate = now('Asia/Kolkata')->addDay()->toDateString();
        $this->showReadyToShipModal = true;
    }

    public function manifestShipment(ShipmentServiceInterface $shipmentService): void
    {
        $order = $this->order;
        if (! $order) {
            return;
        }

        $this->authorize('update', $order);

        $this->validate([
            'boxWeightGrams' => ['required', 'integer', 'min:50', 'max:50000'],
            'boxLengthCm' => ['required', 'integer', 'min:1', 'max:200'],
            'boxWidthCm' => ['required', 'integer', 'min:1', 'max:200'],
            'boxHeightCm' => ['required', 'integer', 'min:1', 'max:200'],
            'boxPickupDate' => ['nullable', 'date'],
        ]);

        try {
            $shipment = $shipmentService->createShipmentForSellerOrder(
                sellerOrderId: $order->id,
                actualWeightGrams: $this->boxWeightGrams,
                lengthCm: $this->boxLengthCm,
                widthCm: $this->boxWidthCm,
                heightCm: $this->boxHeightCm,
                pickupDate: $this->boxPickupDate,
            );

            session()->flash('order_success', "Shipment manifested successfully with {$shipment->provider} (AWB: {$shipment->awb}). Packing label is ready.");
            $this->showReadyToShipModal = false;
            unset($this->order);
        } catch (\DomainException $e) {
            session()->flash('order_error', $e->getMessage());
        }
    }

    public function cancelShipmentAction(string $shipmentId, ShipmentServiceInterface $shipmentService): void
    {
        $order = $this->order;
        if (! $order) {
            return;
        }

        $this->authorize('update', $order);

        try {
            $shipmentService->cancelShipment(
                shipmentId: $shipmentId,
                actorType: 'seller',
                actorId: auth()->id(),
                reason: 'Shipment cancelled by seller'
            );

            session()->flash('order_success', 'Shipment cancelled. Order reverted to Packed status.');
            unset($this->order);
        } catch (\DomainException $e) {
            session()->flash('order_error', $e->getMessage());
        }
    }

    public function registerWarehouseAction(CarrierManagerInterface $carrierManager, SellerPickupAddressLookupInterface $addressLookup): void
    {
        $order = $this->order;
        if (! $order) {
            return;
        }

        $this->authorize('update', $order);

        $addr = $addressLookup->getSellerPickupAddress($order->seller_profile_id);
        if (! $addr) {
            session()->flash('order_error', 'Seller pickup address not found.');

            return;
        }

        $driver = $carrierManager->driver();
        $res = $driver->registerPickupAddress($addr);

        if ($res->success) {
            session()->flash('order_success', "Warehouse registered with carrier (Alias: {$res->alias}).");
        } else {
            session()->flash('order_error', 'Warehouse registration failed: '.$res->message);
        }
    }

    public function submitReturnReport(string $returnRequestId, \App\Returns\Contracts\ReturnRequestServiceInterface $returns): void
    {
        $order = $this->order;
        if (! $order) {
            return;
        }

        $this->authorize('update', $order);

        $this->validate([
            'returnCondition' => ['required', 'in:intact,damaged,wrong_item,incomplete'],
            'returnNotes' => ['nullable', 'string', 'max:1000'],
            'returnPhotos' => ['nullable', 'array', 'max:5'],
            'returnPhotos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        try {
            $returns->submitSellerReport($returnRequestId, (int) $order->seller_profile_id, $this->returnCondition, $this->returnNotes, $this->returnPhotos);
            session()->flash('order_success', 'Return condition report submitted.');
            $this->reset(['returnNotes', 'returnPhotos']);
        } catch (\Throwable $e) {
            session()->flash('order_error', 'Submission failed: '.$e->getMessage());
        }
    }

    public function submitRtoInspection(RtoIntakeServiceInterface $rtoIntakeService): void
    {
        $order = $this->order;
        if (! $order) {
            return;
        }

        $this->authorize('update', $order);

        if ($order->status !== SellerOrderStatus::ReturnedToOrigin) {
            session()->flash('order_error', 'A condition report can only be submitted for a package returned to you (RTO).');

            return;
        }

        if (app(ReturnsLookupInterface::class)->isRtoInspectionDeadlinePassed($order->rto_delivered_at?->toIso8601String())) {
            session()->flash('order_error', 'The inspection window for this returned package has closed.');

            return;
        }

        $this->validate([
            'rtoCondition' => ['required', 'string', 'in:intact,damaged,wrong_item,incomplete'],
            'rtoNotes' => ['nullable', 'string', 'max:1000'],
            'rtoPhotos' => ['nullable', 'array', 'max:5'],
            // mimes is checked against the file contents, not just the extension
            'rtoPhotos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        // Private disk: photos are served only through authorised routes (owning seller, or admin with returns.view)
        $storedPaths = [];
        foreach ($this->rtoPhotos as $file) {
            $storedPaths[] = $file->store("rto_photos/{$order->id}", 'local');
        }

        try {
            $rtoIntakeService->submitRtoReport(
                sellerOrderId: $order->id,
                conditionStatus: $this->rtoCondition,
                notes: $this->rtoNotes,
                photos: $storedPaths,
            );

            session()->flash('order_success', 'RTO inspection report submitted successfully.');
            $this->rtoPhotos = [];
            unset($this->order);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($storedPaths);
            session()->flash('order_error', 'Submission failed: '.$e->getMessage());
        }
    }
}; ?>

<div class="space-y-6">
    <!-- Breadcrumb & Back -->
    <div class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('seller.orders.index') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>&larr; Back to Orders</a>
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

    @php
        $sellerOrder = $this->order;
        $feeLookup = app(App\Fees\Contracts\OrderItemFeeSnapshotLookupInterface::class);
        $feeSummary = $sellerOrder ? $feeLookup->getSellerOrderFeeSummary($sellerOrder->id, $sellerOrder->seller_profile_id) : null;
        $itemSnapshots = $sellerOrder ? collect($feeLookup->getSnapshotsForSellerOrder($sellerOrder->id, $sellerOrder->seller_profile_id))->keyBy(fn ($s) => $s->orderItemId) : collect();
        $shipmentLookup = app(\App\Shipping\Contracts\ShipmentLookupInterface::class);
        $shipment = $sellerOrder ? $shipmentLookup->getShipmentForSellerOrder($sellerOrder->id) : null;
        $warehouseProfile = $sellerOrder ? $shipmentLookup->getSellerShippingProfile($sellerOrder->seller_profile_id) : null;
    @endphp

    <!-- Header Section with Action Buttons -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div>
            <span class="text-xs font-bold uppercase tracking-wider text-zinc-400">Order</span>
            <h1 class="text-xl font-extrabold text-zinc-900 dark:text-white">
                {{ $sellerOrder->order->order_number ?? $sellerOrder->id }}
            </h1>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Placed on @datetime($sellerOrder->placed_at)
            </p>
            @if ($sellerOrder->courier_name || $sellerOrder->tracking_number)
                <div class="mt-2 inline-flex items-center gap-2 rounded-lg bg-zinc-100 px-2.5 py-1 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                    <span class="font-bold text-zinc-900 dark:text-white">Courier:</span> {{ $sellerOrder->courier_name ?? '—' }}
                    <span class="text-zinc-300 dark:text-zinc-600">&bull;</span>
                    <span class="font-bold text-zinc-900 dark:text-white">Tracking:</span> <span class="font-mono">{{ $sellerOrder->tracking_number ?? '—' }}</span>
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('seller.support.create', ['order' => $sellerOrder->id]) }}" wire:navigate data-test="seller-order-help-link"
               class="text-xs font-bold uppercase tracking-wider text-amber-600 hover:text-amber-700 dark:text-amber-400">
                Need help with this order?
            </a>
            <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-bold uppercase {{ $sellerOrder->status->value === 'cancelled' ? 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300' : 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300' }}">
                Status: {{ $sellerOrder->status->label() }}
            </span>

            @if ($sellerOrder->status->canConfirm())
                <button
                    type="button"
                    wire:click="confirmOrder"
                    class="rounded-xl bg-amber-500 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-amber-600 transition"
                >
                    Confirm Order
                </button>
            @endif

            @if ($sellerOrder->status->canPack())
                <button
                    type="button"
                    wire:click="markPacked"
                    class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-indigo-700 transition"
                >
                    Mark as Packed
                </button>
            @endif

            @if ($sellerOrder->status->canShip() && ! $this->showReadyToShipModal && ! $this->showShipModal)
                <button
                    type="button"
                    wire:click="openReadyToShipModal"
                    class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-indigo-700 transition"
                >
                    Ready to Ship (Ekart)
                </button>
                <button
                    type="button"
                    wire:click="$set('showShipModal', true)"
                    class="rounded-xl border border-zinc-300 bg-white px-4 py-2 text-xs font-bold uppercase tracking-wider text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 transition"
                >
                    Manual Dispatch
                </button>
            @endif

            @if ($shipment && $shipment->labelPath)
                <a
                    href="{{ route('shipping.shipments.label', ['shipment' => $shipment->id]) }}"
                    target="_blank"
                    class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-emerald-700 transition"
                >
                    Download Label
                </a>
            @endif

            @if ($sellerOrder->status->canCancelBySeller() && ! $this->showCancelModal)
                <button
                    type="button"
                    wire:click="$set('showCancelModal', true)"
                    class="rounded-xl border border-red-300 bg-white px-4 py-2 text-xs font-bold uppercase tracking-wider text-red-600 hover:bg-red-50 dark:border-red-800 dark:bg-zinc-800 dark:text-red-300 transition"
                >
                    Cancel Order
                </button>
            @endif
        </div>
    </div>

    <!-- Cancellation Prompt Form -->
    @if ($this->showCancelModal)
        <div class="rounded-2xl border border-red-200 bg-red-50/50 p-5 dark:border-red-900/60 dark:bg-red-950/30">
            <h3 class="text-sm font-bold text-red-900 dark:text-red-300">Seller Cancellation</h3>
            <p class="mt-1 text-xs text-red-700 dark:text-red-400">
                Cancelling this order will release the reserved inventory back to live stock.
            </p>
            <div class="mt-3">
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Reason for cancellation:</label>
                <input
                    type="text"
                    wire:model="cancelReason"
                    placeholder="e.g. Damaged inventory during inspection"
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
                    wire:click="$set('showCancelModal', false)"
                    class="text-xs font-semibold text-zinc-500 hover:text-zinc-800 dark:text-zinc-400"
                >
                    Nevermind
                </button>
            </div>
        </div>
    @endif

    <!-- Ready to Ship (Carrier Manifestation) Modal -->
    @if ($this->showReadyToShipModal)
        @php
            $divisor = (int) config('shipping.volumetric_divisor', 5000);
            $volWeight = (int) round(($this->boxLengthCm * $this->boxWidthCm * $this->boxHeightCm) / $divisor * 1000);
            $chargeableWeight = max($this->boxWeightGrams, $volWeight);
        @endphp
        <div class="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-6 dark:border-indigo-900/60 dark:bg-indigo-950/30">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-base font-bold text-indigo-950 dark:text-indigo-200">Ready to Ship: Carrier Manifestation</h3>
                    <p class="mt-1 text-xs text-indigo-800 dark:text-indigo-400">
                        Specify actual package box weight and dimensions. Volumetric weight will be automatically calculated.
                    </p>
                </div>
                <button type="button" wire:click="$set('showReadyToShipModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 text-sm font-bold">&times;</button>
            </div>

            <!-- Warehouse Registration Warning -->
            @if ($warehouseProfile && $warehouseProfile->isRegistered)
                <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-300 flex items-center justify-between">
                    <span>✓ Warehouse registered with carrier: <strong>{{ $warehouseProfile->warehouseAlias }}</strong></span>
                </div>
            @else
                <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-300 flex items-center justify-between gap-3">
                    <span>⚠️ Pickup warehouse not yet registered with Ekart. You can sync it now.</span>
                    <button type="button" wire:click="registerWarehouseAction" class="rounded-lg bg-amber-600 px-3 py-1 text-xs font-bold text-white hover:bg-amber-700">
                        Register Warehouse Now
                    </button>
                </div>
            @endif

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300 mb-1">
                        Actual Weight (grams) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" min="50" step="10" wire:model.live="boxWeightGrams" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                    @error('boxWeightGrams') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300 mb-1">
                        Length (cm) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" min="1" step="1" wire:model.live="boxLengthCm" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                    @error('boxLengthCm') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300 mb-1">
                        Width (cm) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" min="1" step="1" wire:model.live="boxWidthCm" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                    @error('boxWidthCm') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300 mb-1">
                        Height (cm) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" min="1" step="1" wire:model.live="boxHeightCm" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                    @error('boxHeightCm') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
            </div>

            <!-- Weight Summary calculation pill -->
            <div class="mt-4 flex flex-wrap items-center gap-4 text-xs bg-white dark:bg-zinc-900 p-3 rounded-xl border border-indigo-100 dark:border-zinc-800">
                <span class="text-zinc-600 dark:text-zinc-400">Actual: <strong class="text-zinc-900 dark:text-white">{{ number_format($this->boxWeightGrams) }} g</strong></span>
                <span class="text-zinc-300 dark:text-zinc-700">&bull;</span>
                <span class="text-zinc-600 dark:text-zinc-400">Volumetric: <strong class="text-zinc-900 dark:text-white">{{ number_format($volWeight) }} g</strong> <span class="text-[10px] text-zinc-400">(L&times;W&times;H / 5000)</span></span>
                <span class="text-zinc-300 dark:text-zinc-700">&bull;</span>
                <span class="text-indigo-700 dark:text-indigo-400 font-bold">Chargeable Weight: {{ number_format($chargeableWeight) }} g</span>
            </div>

            <div class="mt-4 max-w-xs">
                <label class="block text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300 mb-1">
                    Preferred Dispatch Date
                </label>
                <input type="date" wire:model="boxPickupDate" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
            </div>

            <div class="mt-6 flex items-center gap-3">
                <button
                    type="button"
                    wire:click="manifestShipment"
                    wire:loading.attr="disabled"
                    class="rounded-xl bg-indigo-600 px-6 py-2.5 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-indigo-700 transition"
                >
                    Manifest Shipment & Generate Label
                </button>
                <button
                    type="button"
                    wire:click="$set('showReadyToShipModal', false)"
                    class="text-xs font-semibold text-zinc-500 hover:text-zinc-800 dark:text-zinc-400"
                >
                    Cancel
                </button>
            </div>
        </div>
    @endif

    <!-- Shipping Prompt Form -->
    @if ($this->showShipModal)
        <div class="rounded-2xl border border-purple-200 bg-purple-50/50 p-5 dark:border-purple-900/60 dark:bg-purple-950/30">
            <h3 class="text-sm font-bold text-purple-900 dark:text-purple-300">Dispatch & Ship Package</h3>
            <p class="mt-1 text-xs text-purple-700 dark:text-purple-400">
                Enter your logistics partner and tracking number. Once shipped, delivery will be tracked by marketplace administration.
            </p>
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-xl">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-purple-800 dark:text-purple-300 mb-1">
                        Courier Name <span class="text-red-500">*</span>
                    </label>
                    <input
                        type="text"
                        wire:model="courierName"
                        placeholder="e.g. Delhivery, Blue Dart, DTDC, Xpressbees"
                        class="w-full rounded-lg border border-purple-200 bg-white px-3 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                    />
                    @error('courierName') <span class="text-xs text-red-500 font-medium">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-purple-800 dark:text-purple-300 mb-1">
                        Tracking Number / AWB <span class="text-red-500">*</span>
                    </label>
                    <input
                        type="text"
                        wire:model="trackingNumber"
                        placeholder="e.g. DEL987654321"
                        class="w-full rounded-lg border border-purple-200 bg-white px-3 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                    />
                    @error('trackingNumber') <span class="text-xs text-red-500 font-medium">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="mt-4 flex items-center gap-3">
                <button
                    type="button"
                    wire:click="confirmShip"
                    class="rounded-xl bg-purple-600 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-purple-700 transition"
                >
                    Confirm Shipment
                </button>
                <button
                    type="button"
                    wire:click="$set('showShipModal', false)"
                    class="text-xs font-semibold text-zinc-500 hover:text-zinc-800 dark:text-zinc-400"
                >
                    Cancel
                </button>
            </div>
        </div>
    @endif

    <!-- Customer Returns (read-only for the seller; condition report once received) -->
    @php
        $packageReturns = \App\Returns\Models\ReturnRequest::with('items')->where('seller_order_id', $sellerOrder->id)->latest()->get();
    @endphp
    @if ($packageReturns->isNotEmpty())
        <div data-test="seller-returns-card" class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Customer returns</h3>

            @foreach ($packageReturns as $return)
                <div data-test="seller-return-{{ $return->id }}" class="rounded-xl bg-zinc-50 p-4 text-xs dark:bg-zinc-800/60 space-y-2">
                    <div class="flex justify-between gap-2">
                        <div>
                            <span class="font-bold text-zinc-900 dark:text-white">{{ $return->reason->label() }}</span>
                            <p class="text-zinc-600 dark:text-zinc-300">
                                @foreach ($return->items as $line)
                                    {{ $sellerOrder->items->firstWhere('id', $line->order_item_id)?->title }} &times; {{ $line->units }}@if (! $loop->last), @endif
                                @endforeach
                            </p>
                            @if ($return->customer_notes)<p class="text-zinc-500">“{{ $return->customer_notes }}”</p>@endif
                        </div>
                        <span class="h-fit rounded-lg bg-zinc-200 px-2 py-0.5 font-bold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">{{ $return->status->label() }}</span>
                    </div>

                    @if (! empty($return->photos))
                        <div class="flex flex-wrap gap-2">
                            @foreach ($return->photos as $index => $photo)
                                <img src="{{ route('seller.returns.request-photo', ['request' => $return->id, 'kind' => 'customer', 'index' => $index]) }}" alt="Customer photo" class="size-14 rounded-lg object-cover border border-zinc-200" />
                            @endforeach
                        </div>
                    @endif

                    @if ($return->seller_reported_at)
                        <p data-test="seller-return-report">Your report: <strong>{{ str_replace('_', ' ', $return->seller_condition) }}</strong>{{ $return->seller_report_notes ? ' — '.$return->seller_report_notes : '' }} (@datetime($return->seller_reported_at))</p>
                    @elseif ($return->status === \App\Returns\Enums\ReturnRequestStatus::Received)
                        @if ($return->sellerReportMissed())
                            <p data-test="seller-return-report-missed" class="font-semibold text-red-600">The report deadline (@datetime($return->report_deadline_at)) has passed; the marketplace will decide without it.</p>
                        @else
                            <form wire:submit.prevent="submitReturnReport('{{ $return->id }}')" class="space-y-2 border-t border-zinc-200 pt-2 dark:border-zinc-700">
                                <p>The return reached you. Report its condition by <strong data-test="return-report-deadline">@datetime($return->report_deadline_at)</strong>.</p>
                                <select wire:model="returnCondition" class="w-full rounded-lg border border-zinc-300 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                                    <option value="intact">Intact / resalable</option>
                                    <option value="damaged">Damaged</option>
                                    <option value="wrong_item">Wrong item returned</option>
                                    <option value="incomplete">Incomplete / missing parts</option>
                                </select>
                                <textarea wire:model="returnNotes" rows="2" placeholder="What you found" class="w-full rounded-lg border border-zinc-300 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"></textarea>
                                <input type="file" wire:model="returnPhotos" multiple accept="image/jpeg,image/png,image/webp" class="w-full" />
                                @error('returnPhotos') <p class="text-red-600">{{ $message }}</p> @enderror
                                @error('returnPhotos.*') <p class="text-red-600">{{ $message }}</p> @enderror
                                <button type="submit" data-test="submit-return-report-btn" class="rounded-lg bg-zinc-900 px-4 py-2 font-bold text-white dark:bg-white dark:text-zinc-900">Submit condition report</button>
                            </form>
                        @endif
                    @endif

                    @if ($return->qc_result)
                        <p data-test="seller-return-qc">QC {{ $return->qc_result }} &middot; outcome: {{ str_replace('_', ' ', $return->qc_outcome) }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <!-- RTO Condition Inspection Card -->
    @if ($sellerOrder->status === \App\Orders\Enums\SellerOrderStatus::ReturnedToOrigin)
        @php
            $returnsLookup = app(\App\Returns\Contracts\ReturnsLookupInterface::class);
            $rtoReport = $returnsLookup->getRtoReportForSellerOrder($sellerOrder->id);
            $daysAllowed = (int) config('returns.seller_rto_inspection_days', 3);
            $rtoDeliveredAt = $sellerOrder->rto_delivered_at;
            $deadline = $rtoDeliveredAt ? \Illuminate\Support\Carbon::parse($rtoDeliveredAt)->addDays($daysAllowed) : null;
            $isDeadlinePassed = $deadline ? now()->isAfter($deadline) : false;
        @endphp

        <div data-test="seller-rto-card" class="rounded-2xl border border-amber-300 bg-amber-50/60 p-6 shadow-xs dark:border-amber-800 dark:bg-amber-950/30 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-amber-200 pb-3 dark:border-amber-900/60">
                <div>
                    <h3 class="text-sm font-bold text-amber-950 dark:text-amber-200">
                        Package Returned to Origin (RTO Intake)
                    </h3>
                    <p class="text-xs text-amber-800 dark:text-amber-300 mt-0.5">
                        Delivered back to your address on @datetime($rtoDeliveredAt ?: $sellerOrder->updated_at).
                        @if (! $rtoReport && $deadline)
                            Inspection deadline: <strong data-test="rto-deadline" class="{{ $isDeadlinePassed ? 'text-red-600' : 'text-amber-900 dark:text-amber-100' }}">@datetime($deadline) ({{ $daysAllowed }} days)</strong>.
                        @endif
                    </p>
                </div>

                @if ($rtoReport)
                    <span class="rounded-lg bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                        Condition Reported: {{ strtoupper($rtoReport->conditionStatus->value) }}
                    </span>
                @elseif ($isDeadlinePassed)
                    <span class="rounded-lg bg-red-100 px-3 py-1 text-xs font-bold text-red-800 dark:bg-red-950 dark:text-red-300">
                        Inspection Period Expired
                    </span>
                @else
                    <span class="rounded-lg bg-amber-200/80 px-3 py-1 text-xs font-bold text-amber-900 dark:bg-amber-900/60 dark:text-amber-200">
                        Action Required: Inspect & Report
                    </span>
                @endif
            </div>

            @if ($rtoReport)
                <div class="text-xs space-y-2">
                    <div>
                        <span class="text-zinc-500">Your inspection notes:</span>
                        <p class="font-medium text-zinc-900 dark:text-white">{{ $rtoReport->notes ?: 'None provided' }}</p>
                    </div>
                    @if (! empty($rtoReport->photos))
                        <div>
                            <span class="text-zinc-500">Uploaded photos ({{ count($rtoReport->photos) }}):</span>
                            <div class="mt-1 flex flex-wrap gap-2">
                                @foreach ($rtoReport->photos as $index => $p)
                                    <img src="{{ route('seller.returns.rto-photo', ['report' => $rtoReport->id, 'index' => $index]) }}" data-test="seller-rto-photo" class="size-16 rounded-lg object-cover border border-amber-200" alt="RTO proof" />
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <div class="pt-2">
                        <span class="text-zinc-500">Marketplace Admin Decision:</span>
                        <strong class="ml-1 uppercase text-zinc-900 dark:text-white">{{ $rtoReport->adminDecision->value }}</strong>
                    </div>
                </div>
            @elseif ($isDeadlinePassed)
                <p class="text-xs text-red-700 dark:text-red-400">
                    The {{ $daysAllowed }}-day inspection window for this returned shipment has elapsed. Marketplace administration will review and decide restocking or disposal directly.
                </p>
            @else
                <form wire:submit.prevent="submitRtoInspection" class="space-y-4 pt-1">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-amber-950 dark:text-amber-200 mb-1">Package / Item Condition <span class="text-red-500">*</span></label>
                            <select wire:model="rtoCondition" data-test="rto-condition-select" class="w-full rounded-lg border border-amber-300 bg-white p-2 text-xs text-zinc-900 dark:border-amber-800 dark:bg-zinc-900 dark:text-white">
                                <option value="intact">Intact / Salable condition</option>
                                <option value="damaged">Damaged in transit / by courier</option>
                                <option value="wrong_item">Wrong item returned</option>
                                <option value="incomplete">Incomplete / missing parts</option>
                            </select>
                            @error('rtoCondition') <p class="text-red-600 text-[11px] mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-amber-950 dark:text-amber-200 mb-1">Proof Photos (up to 5; JPG, PNG or WebP, max 5 MB each)</label>
                            <input type="file" wire:model="rtoPhotos" multiple accept="image/jpeg,image/png,image/webp" class="w-full text-xs text-zinc-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-amber-100 file:text-amber-800 hover:file:bg-amber-200" />
                            @error('rtoPhotos') <p class="text-red-600 text-[11px] mt-1">{{ $message }}</p> @enderror
                            @error('rtoPhotos.*') <p class="text-red-600 text-[11px] mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-amber-950 dark:text-amber-200 mb-1">Inspection Notes</label>
                        <textarea wire:model="rtoNotes" rows="2" placeholder="Details about seal condition, packaging defects, or courier damage..." class="w-full rounded-lg border border-amber-300 bg-white p-2 text-xs text-zinc-900 dark:border-amber-800 dark:bg-zinc-900 dark:text-white"></textarea>
                        @error('rtoNotes') <p class="text-red-600 text-[11px] mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end">
                        <button
                            type="submit"
                            data-test="submit-rto-report-btn"
                            class="rounded-xl bg-amber-600 px-5 py-2 text-xs font-bold uppercase tracking-wider text-white hover:bg-amber-700 transition shadow-xs"
                        >
                            Submit Inspection Report
                        </button>
                    </div>
                </form>
            @endif
        </div>
    @endif

    <!-- Content Columns -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left: Items Table -->
        <div class="lg:col-span-2 space-y-6">
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div class="border-b border-zinc-100 bg-zinc-50/50 px-6 py-3.5 text-xs font-bold uppercase tracking-wider text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/30 dark:text-zinc-400">
                    Order Items ({{ $sellerOrder->items->count() }})
                </div>

                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($sellerOrder->items as $item)
                        <div class="p-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                            <div class="flex items-center gap-4">
                                <div class="h-16 w-16 flex-shrink-0 overflow-hidden rounded-xl border border-zinc-200 bg-white p-1.5 dark:border-zinc-800 dark:bg-zinc-800">
                                    <img
                                        src="{{ $item->image_path ?: asset('storage/products/placeholder.svg') }}"
                                        alt="{{ $item->title }}"
                                        class="h-full w-full object-contain"
                                    />
                                </div>
                                <div>
                                    <h4 class="font-bold text-zinc-900 dark:text-white text-sm">
                                        {{ $item->title }}
                                    </h4>
                                    @if ($item->variant_title)
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $item->variant_title }}
                                        </p>
                                    @endif
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        SKU: <code class="text-zinc-700 dark:text-zinc-300">{{ $item->sku }}</code> &middot; UPID: <code class="text-zinc-700 dark:text-zinc-300">{{ $item->upid }}</code>
                                    </p>
                                    @if ($item->hsn_code)
                                        <p class="text-[11px] text-zinc-400">
                                            HSN: {{ $item->hsn_code }} &middot; GST Rate: {{ $item->gst_rate }}% (Tax: ₹{{ number_format($item->gst_amount / 100) }})
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <div class="text-right sm:text-right">
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Qty: <strong>{{ $item->qty }}</strong> &times; ₹{{ number_format($item->unit_price / 100) }}
                                </p>
                                <p class="text-sm font-extrabold text-zinc-900 dark:text-white mt-0.5">
                                    ₹{{ number_format($item->line_total / 100) }}
                                </p>
                                @if (($item->coupon_discount_amount ?? 0) > 0)
                                    <div class="mt-1 text-[11px] text-emerald-600 dark:text-emerald-400">
                                        @if ($item->coupon_funded_by === 'platform')
                                            <span class="inline-flex flex-col items-end">
                                                <span class="font-medium">Platform promotion: −₹{{ number_format($item->coupon_discount_amount / 100) }}</span>
                                                <span class="text-[10px] text-zinc-400 dark:text-zinc-500">(not deducted from your payout)</span>
                                            </span>
                                        @else
                                            <span>Seller concession: −₹{{ number_format($item->coupon_discount_amount / 100) }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if ($itemSnapshots->has($item->id))
                            @php $snap = $itemSnapshots->get($item->id); @endphp
                            <div class="px-6 pb-4 pt-1">
                                <div class="rounded-xl border border-zinc-100 bg-zinc-50/75 p-3 text-xs dark:border-zinc-800 dark:bg-zinc-800/40">
                                    <div class="flex items-center justify-between font-semibold">
                                        <span class="text-emerald-700 dark:text-emerald-400">Net Item Payout: {{ $snap->netPayout->format() }}</span>
                                        <span class="text-zinc-500 font-mono text-[11px]">Total Deductions: -{{ $snap->totalDeductions->format() }}</span>
                                    </div>
                                    <div class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2 text-[10px] text-zinc-500">
                                        <div>Commission: <span class="font-mono text-red-600">-{{ $snap->commission->format() }}</span></div>
                                        <div>Closing Fee: <span class="font-mono text-red-600">-{{ $snap->closingFee->format() }}</span></div>
                                        <div>Shipping Fee: <span class="font-mono text-red-600">-{{ $snap->shippingFee->format() }}</span></div>
                                        <div>Taxes (GST+TCS+TDS): <span class="font-mono text-red-600">-{{ $snap->claimableCredits->format() }}</span></div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            <!-- Carrier Shipment & Tracking Timeline -->
            @if ($shipment)
                <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                    <div class="border-b border-zinc-100 bg-zinc-50/50 px-6 py-3.5 flex items-center justify-between dark:border-zinc-800 dark:bg-zinc-800/30">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">
                            Logistics Shipment ({{ strtoupper($shipment->provider) }})
                        </span>
                        <span class="inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-bold {{ $shipment->status->badgeClasses() }}">
                            {{ $shipment->status->label() }}
                        </span>
                    </div>

                    <div class="p-6 space-y-4">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                            <div>
                                <span class="text-zinc-400 uppercase text-[10px] font-bold">Waybill / AWB</span>
                                <p class="font-mono font-bold text-zinc-900 dark:text-white mt-0.5">{{ $shipment->awb ?? '—' }}</p>
                            </div>
                            <div>
                                <span class="text-zinc-400 uppercase text-[10px] font-bold">Chargeable Weight</span>
                                <p class="font-bold text-zinc-900 dark:text-white mt-0.5">{{ number_format($shipment->chargeableWeightGrams) }} g</p>
                            </div>
                            <div>
                                <span class="text-zinc-400 uppercase text-[10px] font-bold">Box (L &times; W &times; H)</span>
                                <p class="font-bold text-zinc-900 dark:text-white mt-0.5">{{ $shipment->lengthCm }} &times; {{ $shipment->widthCm }} &times; {{ $shipment->heightCm }} cm</p>
                            </div>
                            <div>
                                <span class="text-zinc-400 uppercase text-[10px] font-bold">Pickup Date</span>
                                <p class="font-bold text-zinc-900 dark:text-white mt-0.5">{{ $shipment->pickupDate ? $shipment->pickupDate->format('d M Y') : 'Immediate' }}</p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 pt-2">
                            @if ($shipment->labelPath)
                                <a
                                    href="{{ route('shipping.shipments.label', ['shipment' => $shipment->id]) }}"
                                    target="_blank"
                                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-emerald-700 transition"
                                >
                                    <span>Download Packing Label</span>
                                </a>
                            @endif

                            @if ($shipment->publicTrackingUrl)
                                <a
                                    href="{{ $shipment->publicTrackingUrl }}"
                                    target="_blank"
                                    class="inline-flex items-center gap-2 rounded-xl border border-zinc-300 bg-white px-4 py-2 text-xs font-bold uppercase tracking-wider text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 transition"
                                >
                                    <span>Track on {{ strtoupper($shipment->provider) }} &rarr;</span>
                                </a>
                            @endif

                            @if (! $shipment->status->isTerminal())
                                <button
                                    type="button"
                                    wire:click="cancelShipmentAction('{{ $shipment->id }}')"
                                    wire:confirm="Cancel this shipment? The package will be cancelled and order reverted to Packed."
                                    class="rounded-xl border border-red-300 bg-white px-4 py-2 text-xs font-bold uppercase tracking-wider text-red-600 hover:bg-red-50 dark:border-red-800 dark:bg-zinc-800 dark:text-red-300 transition"
                                >
                                    Cancel Shipment
                                </button>
                            @endif
                        </div>

                        <!-- Tracking Events Timeline -->
                        @if (! empty($shipment->events))
                            <div class="mt-6 border-t border-zinc-100 dark:border-zinc-800 pt-4">
                                <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-400 mb-3">Shipment History</h4>
                                <ol class="relative border-l border-zinc-200 dark:border-zinc-700 ml-3 space-y-4">
                                    @foreach ($shipment->events as $evt)
                                        <li class="ml-4">
                                            <div class="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full border border-white bg-indigo-600 dark:border-zinc-900"></div>
                                            <div class="flex items-baseline justify-between gap-2">
                                                <span class="text-xs font-bold text-zinc-900 dark:text-white">{{ $evt->providerStatus }}</span>
                                                <time class="text-[10px] text-zinc-400">@datetime($evt->occurredAt)</time>
                                            </div>
                                            @if ($evt->location)
                                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $evt->location }}</p>
                                            @endif
                                            @if ($evt->description)
                                                <p class="text-xs text-zinc-600 dark:text-zinc-300 mt-0.5">{{ $evt->description }}</p>
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <!-- Activity Log -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    Status History & Activity
                </h3>
                <div class="mt-4 space-y-3">
                    @forelse ($sellerOrder->statusHistories as $log)
                        <div class="flex items-start gap-3 text-xs">
                            <span class="mt-1 h-2 w-2 rounded-full bg-amber-500"></span>
                            <div>
                                <span class="font-bold text-zinc-900 dark:text-white">{{ ucfirst($log->to_status) }}</span>
                                <span class="text-zinc-500">by {{ ucfirst($log->changed_by_type) }} on @datetime($log->created_at)</span>
                                @if ($log->reason)
                                    <p class="mt-0.5 text-zinc-600 dark:text-zinc-400 italic">"{{ $log->reason }}"</p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-zinc-400">No activity recorded yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Right: Order Value & Delivery Info -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Order Value & Package Deductions Card -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-3">
                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    Package Value & Deductions
                </h3>

                @if ($sellerOrder->invoice_number)
                    <div class="rounded-xl border border-indigo-100 bg-indigo-50/50 p-3 text-xs dark:border-indigo-900/50 dark:bg-indigo-950/20">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-400">GST Tax Invoice</span>
                        <p class="font-mono font-bold text-zinc-900 dark:text-white mt-0.5">{{ $sellerOrder->invoice_number }}</p>
                        @if ($sellerOrder->invoice_date)
                            <p class="text-[11px] text-zinc-500">Date: @datetime($sellerOrder->invoice_date)</p>
                        @endif
                    </div>
                @endif

                <div class="flex items-baseline justify-between">
                    <span class="text-xs text-zinc-600 dark:text-zinc-400">Gross Items Total</span>
                    <span class="text-sm font-bold text-zinc-900 dark:text-white">
                        ₹{{ number_format(($sellerOrder->subtotal ?? $sellerOrder->grand_total) / 100, 2) }}
                    </span>
                </div>

                @if (($sellerOrder->coupon_discount_total ?? 0) > 0)
                    <div class="flex items-baseline justify-between text-xs text-emerald-600 dark:text-emerald-400">
                        <span>Platform Promotion <span class="text-[10px] text-zinc-400">(not deducted)</span></span>
                        <span>−₹{{ number_format($sellerOrder->coupon_discount_total / 100, 2) }}</span>
                    </div>
                @endif

                <div class="flex items-baseline justify-between border-t border-zinc-100 dark:border-zinc-800 pt-2">
                    <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">Customer Net Payable</span>
                    <span class="text-lg font-bold text-zinc-900 dark:text-white">
                        ₹{{ number_format($sellerOrder->grand_total / 100, 2) }}
                    </span>
                </div>

                @if ($feeSummary)
                    <div class="pt-2 border-t border-zinc-100 dark:border-zinc-800 space-y-2 text-xs">
                        <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                            <span>Marketplace Commission</span>
                            <span class="font-mono text-red-600 dark:text-red-400">-{{ $feeSummary->commissionTotal->format() }}</span>
                        </div>
                        <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                            <span>Fixed Closing Fees</span>
                            <span class="font-mono text-red-600 dark:text-red-400">-{{ $feeSummary->closingFeeTotal->format() }}</span>
                        </div>
                        <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                            <span>Package Shipping Fee</span>
                            <span class="font-mono text-red-600 dark:text-red-400">-{{ $feeSummary->shippingFeeTotal->format() }}</span>
                        </div>
                        @if ($feeSummary->collectionFeeTotal->isPositive())
                            <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                                <span>Collection Fee</span>
                                <span class="font-mono text-red-600 dark:text-red-400">-{{ $feeSummary->collectionFeeTotal->format() }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                            <span>GST on Fees (18%)</span>
                            <span class="font-mono text-red-600 dark:text-red-400">-{{ $feeSummary->gstOnFeesTotal->format() }}</span>
                        </div>
                        <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                            <span>GST TCS (0.5%)</span>
                            <span class="font-mono text-red-600 dark:text-red-400">-{{ $feeSummary->tcsTotal->format() }}</span>
                        </div>
                        <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                            <span>Income Tax TDS (0.1%)</span>
                            <span class="font-mono text-red-600 dark:text-red-400">-{{ $feeSummary->tdsTotal->format() }}</span>
                        </div>

                        <div class="pt-2 border-t border-zinc-100 dark:border-zinc-800 flex justify-between font-bold text-zinc-900 dark:text-white">
                            <span>Total Deductions</span>
                            <span class="font-mono text-red-600 dark:text-red-400">-{{ $feeSummary->totalDeductions->format() }}</span>
                        </div>

                        <div class="rounded-xl bg-emerald-50 p-3 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-900/60 mt-3">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-800 dark:text-emerald-300">Net Bank Payout</span>
                            <div class="text-xl font-extrabold text-emerald-900 dark:text-emerald-200 mt-0.5">
                                {{ $feeSummary->netPayout->format() }}
                            </div>
                        </div>

                        <div class="rounded-xl bg-blue-50 p-3 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-900/60">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-blue-800 dark:text-blue-300">Claimable Tax Credits</span>
                            <div class="text-sm font-bold text-blue-900 dark:text-blue-200 mt-0.5">
                                {{ $feeSummary->claimableCredits->format() }}
                            </div>
                            <p class="text-[10px] text-blue-600 dark:text-blue-400 mt-0.5">GST ITC + TCS cash ledger + TDS 26AS</p>
                        </div>
                    </div>
                @else
                    <div class="text-[11px] text-zinc-400 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                        All prices are GST-inclusive.
                    </div>
                @endif
            </div>

            <!-- Customer Destination -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-3">
                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    Shipping Destination
                </h3>
                <div class="text-xs text-zinc-700 dark:text-zinc-300 space-y-1">
                    <p class="font-bold text-zinc-900 dark:text-white">{{ $sellerOrder->order->delivery_address['name'] ?? 'Recipient' }}</p>
                    <p class="text-zinc-600 dark:text-zinc-400 font-medium">{{ $sellerOrder->order->delivery_address['address_line'] ?? ($sellerOrder->order->delivery_address['line'] ?? '') }}</p>
                    @if (! empty($sellerOrder->order->delivery_address['locality']))
                        <p class="text-zinc-500">{{ $sellerOrder->order->delivery_address['locality'] }}</p>
                    @endif
                    @if (! empty($sellerOrder->order->delivery_address['landmark']))
                        <p class="text-zinc-500">Landmark: {{ $sellerOrder->order->delivery_address['landmark'] }}</p>
                    @endif
                    <p class="text-zinc-500">{{ $sellerOrder->order->delivery_address['city'] ?? '' }}, {{ $sellerOrder->order->delivery_state }} - {{ $sellerOrder->order->delivery_address['pincode'] ?? '' }}</p>
                    {{-- Customer contact numbers go to the courier only; questions go through marketplace support --}}
                    <p data-test="customer-phone-hidden" class="pt-1 text-[11px] text-zinc-400">The customer's phone number is shared with the courier only. For questions about this order, contact {{ brand_name() }} Support.</p>
                </div>
            </div>
        </div>
    </div>
</div>

