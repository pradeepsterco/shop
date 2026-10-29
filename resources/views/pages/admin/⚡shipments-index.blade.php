<?php

namespace App\Livewire\Admin;

use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Shipments')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $provider = 'all';

    public function mount(): void
    {
        $this->authorize('shipping.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingProvider(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function shipments()
    {
        return Shipment::query()
            ->when($this->search !== '', function ($q) {
                $q->where(function ($sub) {
                    $sub->where('awb', 'like', "%{$this->search}%")
                        ->orWhere('vendor_wbn', 'like', "%{$this->search}%")
                        ->orWhere('seller_order_id', 'like', "%{$this->search}%")
                        ->orWhere('order_id', 'like', "%{$this->search}%");
                });
            })
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->provider !== 'all', fn ($q) => $q->where('provider', $this->provider))
            ->latest()
            ->paginate(15);
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-zinc-900 dark:text-white">Shipments</h1>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">Track carrier manifests, dispatch statuses, and delivery confirmations.</p>
        </div>

        <div class="flex items-center gap-3">
            <a
                href="{{ route('admin.shipping.settings') }}"
                wire:navigate
                class="rounded-xl border border-zinc-300 bg-white px-4 py-2 text-xs font-bold uppercase tracking-wider text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 transition"
            >
                Settings
            </a>
        </div>
    </div>

    <!-- Filters Bar -->
    <div class="flex flex-col sm:flex-row items-center gap-3 bg-white p-4 rounded-2xl border border-zinc-200 dark:bg-zinc-900 dark:border-zinc-800">
        <div class="w-full sm:w-72">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by AWB, order ID..."
                class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
            />
        </div>

        <div class="w-full sm:w-48">
            <select
                wire:model.live="status"
                class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
            >
                <option value="all">All Statuses</option>
                @foreach (ShipmentStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="w-full sm:w-44">
            <select
                wire:model.live="provider"
                class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
            >
                <option value="all">All Carriers</option>
                <option value="ekart">Ekart</option>
                <option value="manual">Manual</option>
                <option value="fake">Fake</option>
            </select>
        </div>
    </div>

    <!-- Shipments Table -->
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-zinc-200 bg-zinc-50/75 dark:border-zinc-800 dark:bg-zinc-800/40 text-zinc-500 font-bold uppercase tracking-wider text-[10px]">
                    <tr>
                        <th class="px-6 py-3.5">AWB / Shipment</th>
                        <th class="px-6 py-3.5">Order / Seller</th>
                        <th class="px-6 py-3.5">Carrier</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5">Weight (Chg.)</th>
                        <th class="px-6 py-3.5">COD Amount</th>
                        <th class="px-6 py-3.5">Created At</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($this->shipments as $shipment)
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/20">
                            <td class="px-6 py-4">
                                <a href="{{ route('admin.shipments.show', ['shipment' => $shipment->id]) }}" wire:navigate class="font-mono font-bold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                                    {{ $shipment->awb ?: $shipment->id }}
                                </a>
                                @if ($shipment->vendor_wbn && $shipment->vendor_wbn !== $shipment->awb)
                                    <p class="text-[10px] text-zinc-400 font-mono">WBN: {{ $shipment->vendor_wbn }}</p>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-bold text-zinc-900 dark:text-white">Seller #{{ $shipment->seller_profile_id }}</span>
                                <p class="text-[10px] text-zinc-400 font-mono truncate max-w-[120px]">{{ $shipment->seller_order_id }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <span class="uppercase font-bold text-zinc-700 dark:text-zinc-300">{{ $shipment->provider }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-lg px-2 py-0.5 text-[11px] font-bold {{ $shipment->status->badgeClasses() }}">
                                    {{ $shipment->statusLabel() }}
                                </span>
                                @if ($shipment->isReverse())
                                    <p data-test="reverse-shipment-badge" class="mt-1 text-[10px] font-bold uppercase text-amber-700 dark:text-amber-400">{{ $shipment->direction->label() }}</p>
                                @endif
                            </td>
                            <td class="px-6 py-4 font-semibold text-zinc-900 dark:text-white">
                                {{ number_format($shipment->chargeable_weight_grams) }} g
                            </td>
                            <td class="px-6 py-4">
                                @if ($shipment->is_cod)
                                    <span class="font-bold text-amber-700 dark:text-amber-400">₹{{ number_format($shipment->cod_amount_paise / 100) }}</span>
                                @else
                                    <span class="text-zinc-400 text-[10px] uppercase">Prepaid</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-zinc-500 whitespace-nowrap">
                                @datetime($shipment->created_at)
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a
                                    href="{{ route('admin.shipments.show', ['shipment' => $shipment->id]) }}"
                                    wire:navigate
                                    class="text-indigo-600 hover:text-indigo-800 font-bold dark:text-indigo-400"
                                >
                                    View &rarr;
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-8 text-center text-zinc-400">
                                No shipments found matching criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
            {{ $this->shipments->links() }}
        </div>
    </div>
</div>

