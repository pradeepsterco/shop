<?php

namespace App\Livewire\Admin;

use App\Shipping\Contracts\ShipmentServiceInterface;
use App\Shipping\Models\Shipment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Shipment Details')] class extends Component {
    public string $shipmentId;

    public function mount(string $shipment): void
    {
        $this->authorize('shipping.view');
        $this->shipmentId = $shipment;

        if (! $this->shipment) {
            abort(404, 'Shipment not found.');
        }
    }

    #[Computed]
    public function shipment(): ?Shipment
    {
        return Shipment::with('events')->find($this->shipmentId);
    }

    public function pollTracking(ShipmentServiceInterface $shipmentService): void
    {
        $this->authorize('shipping.manage');

        $shipmentModel = $this->shipment;
        if (! $shipmentModel) {
            return;
        }

        try {
            $shipmentService->syncTracking($shipmentModel);
            session()->flash('shipment_success', 'Tracking successfully synced with carrier.');
            unset($this->shipment);
        } catch (\Throwable $e) {
            session()->flash('shipment_error', 'Tracking sync failed: '.$e->getMessage());
        }
    }

    public function cancelShipment(ShipmentServiceInterface $shipmentService): void
    {
        $this->authorize('shipping.manage');

        $shipmentModel = $this->shipment;
        if (! $shipmentModel) {
            return;
        }

        try {
            $shipmentService->cancelShipment(
                shipmentId: $shipmentModel->id,
                actorType: 'admin',
                actorId: auth()->id(),
                reason: 'Shipment cancelled by administration',
            );

            session()->flash('shipment_success', 'Shipment cancelled and seller order reverted to Packed.');
            unset($this->shipment);
        } catch (\Throwable $e) {
            session()->flash('shipment_error', 'Cancellation failed: '.$e->getMessage());
        }
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('admin.shipments.index') }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">&larr; Back to Shipments</a>
    </div>

    @if (session()->has('shipment_success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300">
            {{ session('shipment_success') }}
        </div>
    @endif
    @if (session()->has('shipment_error'))
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs font-semibold text-red-800 dark:border-red-900/50 dark:bg-red-950/50 dark:text-red-300">
            {{ session('shipment_error') }}
        </div>
    @endif

    @php $shipmentModel = $this->shipment; @endphp

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div>
            <span class="text-xs font-bold uppercase tracking-wider text-zinc-400">Shipment</span>
            <h1 class="text-xl font-extrabold text-zinc-900 dark:text-white font-mono">
                {{ $shipmentModel->awb ?: $shipmentModel->id }}
            </h1>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Created on @datetime($shipmentModel->created_at) &bull; Provider: <strong class="uppercase text-zinc-700 dark:text-zinc-300">{{ $shipmentModel->provider }}</strong>
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-bold {{ $shipmentModel->status->badgeClasses() }}">
                Status: {{ $shipmentModel->status->label() }}
            </span>

            @if ($shipmentModel->label_path)
                <a
                    href="{{ route('shipping.shipments.label', ['shipment' => $shipmentModel->id]) }}"
                    target="_blank"
                    class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white hover:bg-emerald-700 transition"
                >
                    Download Label PDF
                </a>
            @endif

            @if ($shipmentModel->public_tracking_url)
                <a
                    href="{{ $shipmentModel->public_tracking_url }}"
                    target="_blank"
                    class="rounded-xl border border-zinc-300 bg-white px-4 py-2 text-xs font-bold uppercase tracking-wider text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 transition"
                >
                    Public Tracking &rarr;
                </a>
            @endif

            @can('shipping.manage')
                @if (! $shipmentModel->status->isTerminal())
                    <button
                        type="button"
                        wire:click="pollTracking"
                        wire:loading.attr="disabled"
                        class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white hover:bg-indigo-700 transition"
                    >
                        Sync Tracking
                    </button>

                    <button
                        type="button"
                        wire:click="cancelShipment"
                        wire:confirm="Cancel this shipment? The order will be reverted to Packed."
                        class="rounded-xl border border-red-300 bg-white px-4 py-2 text-xs font-bold uppercase tracking-wider text-red-600 hover:bg-red-50 dark:border-red-800 dark:bg-zinc-800 dark:text-red-300 transition"
                    >
                        Cancel Shipment
                    </button>
                @endif
            @endcan
        </div>
    </div>

    <!-- Details Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left: Specifications & Timeline -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Package Specs Card -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    Package Dimensions & Weights
                </h3>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                    <div>
                        <span class="text-zinc-400 uppercase text-[10px] font-bold">Actual Weight</span>
                        <p class="font-bold text-zinc-900 dark:text-white mt-1">{{ number_format($shipmentModel->actual_weight_grams) }} g</p>
                    </div>
                    <div>
                        <span class="text-zinc-400 uppercase text-[10px] font-bold">Volumetric Weight</span>
                        <p class="font-bold text-zinc-900 dark:text-white mt-1">{{ number_format($shipmentModel->volumetric_weight_grams) }} g</p>
                    </div>
                    <div>
                        <span class="text-zinc-400 uppercase text-[10px] font-bold">Chargeable Weight</span>
                        <p class="font-bold text-indigo-600 dark:text-indigo-400 mt-1">{{ number_format($shipmentModel->chargeable_weight_grams) }} g</p>
                    </div>
                    <div>
                        <span class="text-zinc-400 uppercase text-[10px] font-bold">Dimensions (L&times;W&times;H)</span>
                        <p class="font-bold text-zinc-900 dark:text-white mt-1">{{ $shipmentModel->length_cm }} &times; {{ $shipmentModel->width_cm }} &times; {{ $shipmentModel->height_cm }} cm</p>
                    </div>
                </div>
            </div>

            <!-- Tracking Timeline -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    Tracking Events History ({{ $shipmentModel->events->count() }})
                </h3>

                @if ($shipmentModel->events->isNotEmpty())
                    <ol class="relative border-l border-zinc-200 dark:border-zinc-700 ml-3 space-y-4">
                        @foreach ($shipmentModel->events as $evt)
                            <li class="ml-4">
                                <div class="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full border border-white bg-indigo-600 dark:border-zinc-900"></div>
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="text-xs font-bold text-zinc-900 dark:text-white">{{ $evt->provider_status }}</span>
                                    <time class="text-[10px] text-zinc-400">@datetime($evt->occurred_at)</time>
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
                @else
                    <p class="text-xs text-zinc-400">No tracking events recorded yet.</p>
                @endif
            </div>

            <!-- Raw Provider Metadata -->
            @if ($shipmentModel->raw_metadata)
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                        Raw Provider Response
                    </h3>
                    <pre class="bg-zinc-50 dark:bg-zinc-950 p-4 rounded-xl text-[11px] font-mono text-zinc-700 dark:text-zinc-300 overflow-x-auto max-h-60">{{ json_encode($shipmentModel->raw_metadata, JSON_PRETTY_PRINT) }}</pre>
                </div>
            @endif
        </div>

        <!-- Right: Order & Financials Info -->
        <div class="lg:col-span-1 space-y-6">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    Order Linkage
                </h3>

                <div class="text-xs space-y-2">
                    <div>
                        <span class="text-zinc-400">Seller Profile ID:</span>
                        <strong class="text-zinc-900 dark:text-white ml-1">#{{ $shipmentModel->seller_profile_id }}</strong>
                    </div>
                    <div>
                        <span class="text-zinc-400">Seller Order ID:</span>
                        <p class="font-mono font-bold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $shipmentModel->seller_order_id }}</p>
                    </div>
                    <div>
                        <span class="text-zinc-400">Parent Order ID:</span>
                        <p class="font-mono text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $shipmentModel->order_id }}</p>
                    </div>
                    <div class="pt-2 border-t border-zinc-100 dark:border-zinc-800">
                        <span class="text-zinc-400">COD Flag:</span>
                        <strong class="ml-1 {{ $shipmentModel->is_cod ? 'text-amber-600' : 'text-zinc-600' }}">
                            {{ $shipmentModel->is_cod ? 'Yes (₹'.number_format($shipmentModel->cod_amount_paise / 100).')' : 'Prepaid (₹0)' }}
                        </strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

