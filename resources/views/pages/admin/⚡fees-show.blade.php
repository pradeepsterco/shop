<?php

namespace App\Livewire\Admin;

use App\Catalog\Contracts\VerticalLookupInterface;
use App\Fees\Contracts\FeeScheduleServiceInterface;
use App\Fees\Models\FeeSchedule;
use App\Services\AdminActivityLogger;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Fee Schedule Details')] class extends Component {
    public int $scheduleId;

    public function mount(int|FeeSchedule $feeSchedule): void
    {
        $this->authorize('fees.view');
        $this->scheduleId = $feeSchedule instanceof FeeSchedule ? $feeSchedule->id : (int) $feeSchedule;
    }

    #[Computed]
    public function schedule(): FeeSchedule
    {
        return FeeSchedule::with(['commissionSlabs', 'closingSlabs', 'shippingSlabs', 'collectionRules'])
            ->findOrFail($this->scheduleId);
    }

    public function cloneSchedule(FeeScheduleServiceInterface $service): void
    {
        $this->authorize('fees.manage');

        $clone = $service->cloneSchedule($this->schedule);
        AdminActivityLogger::log('fee_schedule.cloned', $clone, [
            'source_id' => $this->schedule->id,
            'name' => $clone->name,
            'version' => $clone->version,
        ]);

        session()->flash('success', "Cloned as Draft v{$clone->version}.");
        $this->redirect(route('admin.fees.edit', $clone), navigate: true);
    }

    public function activateSchedule(FeeScheduleServiceInterface $service): void
    {
        $this->authorize('fees.manage');

        $service->activateSchedule($this->schedule);
        AdminActivityLogger::log('fee_schedule.activated', $this->schedule, [
            'name' => $this->schedule->name,
            'version' => $this->schedule->version,
        ]);

        session()->flash('success', "Schedule v{$this->schedule->version} is now Active.");
        unset($this->schedule);
    }
}; ?>

<div class="space-y-6">
    @php
        $sched = $this->schedule;
        $verticalLookup = app(App\Catalog\Contracts\VerticalLookupInterface::class);
    @endphp

    <!-- Breadcrumb & Top Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-zinc-500 mb-1">
                <a href="{{ route('admin.fees.index') }}" wire:navigate class="hover:underline">&larr; Back to Fee Schedules</a>
            </div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                    {{ $sched->name }}
                </h1>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold uppercase tracking-wider {{ $sched->status->badgeClasses() }}">
                    {{ $sched->status->label() }} (v{{ $sched->version }})
                </span>
            </div>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                @if ($sched->effective_from)
                    Effective: @datetime($sched->effective_from) &rarr; {{ $sched->effective_to ? format_datetime($sched->effective_to) : 'Ongoing' }}
                @else
                    Draft schedule &middot; Not active yet
                @endif
            </p>
        </div>

        @can('fees.manage')
            <div class="flex items-center gap-3">
                @if ($sched->isDraft())
                    <a href="{{ route('admin.fees.edit', $sched) }}"
                       wire:navigate
                       class="rounded-lg bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                        Edit Draft Slabs
                    </a>

                    <button type="button"
                            wire:click="activateSchedule"
                            class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-700 transition shadow-xs">
                        Activate Schedule
                    </button>
                @endif

                <button type="button"
                        wire:click="cloneSchedule"
                        class="rounded-lg border border-zinc-200 bg-white px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 transition">
                    Clone as New Version
                </button>
            </div>
        @endcan
    </div>

    <!-- Alerts -->
    @if (session()->has('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    <!-- Tax & Calculation Rates Overview -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-400">GST on Marketplace Fees</span>
            <div class="mt-2 text-2xl font-black text-zinc-900 dark:text-white">{{ $sched->gst_rate }}%</div>
            <p class="mt-1 text-[11px] text-zinc-500">Applied to total marketplace commissions & fees.</p>
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-400">GST TCS Rate</span>
            <div class="mt-2 text-2xl font-black text-zinc-900 dark:text-white">{{ $sched->tcs_rate }}%</div>
            <p class="mt-1 text-[11px] text-zinc-500">Applied to GST-exclusive product taxable value.</p>
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-400">Income Tax TDS (Sec 194-O)</span>
            <div class="mt-2 text-2xl font-black text-zinc-900 dark:text-white">{{ $sched->tds_standard_rate }}%</div>
            <p class="mt-1 text-[11px] text-zinc-500">Standard rate on gross (5.00% without PAN).</p>
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-400">Default Package Weight</span>
            <div class="mt-2 text-2xl font-black text-zinc-900 dark:text-white">{{ $sched->default_weight_grams }}g</div>
            <p class="mt-1 text-[11px] text-zinc-500">Used when product variant weight is unspecified.</p>
        </div>
    </div>

    <!-- Section 1: Commission Slabs -->
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <div class="border-b border-zinc-100 bg-zinc-50/50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-800/30">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">1. Commission Slabs</h3>
            <p class="text-xs text-zinc-500">Percentage commission deducted from item gross selling price.</p>
        </div>
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="border-b border-zinc-200 bg-zinc-50/75 dark:border-zinc-800 dark:bg-zinc-800/40 text-zinc-500 font-semibold uppercase tracking-wider text-[11px]">
                    <th class="px-6 py-3">Vertical / Category</th>
                    <th class="px-6 py-3">Price Slab (Paise / INR)</th>
                    <th class="px-6 py-3 text-right">Commission Rate</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($sched->commissionSlabs->sortBy(['vertical_id', 'min_price']) as $c)
                    <tr>
                        <td class="px-6 py-3 font-semibold text-zinc-900 dark:text-white">
                            @if ($c->vertical_id)
                                {{ $verticalLookup->findVerticalName($c->vertical_id) ?? "Vertical #{$c->vertical_id}" }}
                            @else
                                <span class="rounded bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 text-zinc-600 dark:text-zinc-400">Default (All Other Verticals)</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 font-mono text-zinc-600 dark:text-zinc-400">
                            ₹{{ number_format($c->min_price / 100, 2) }} &rarr; {{ $c->max_price ? '₹'.number_format($c->max_price / 100, 2) : 'Above' }}
                        </td>
                        <td class="px-6 py-3 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400">
                            {{ number_format($c->rate_bps / 100, 2) }}% ({{ $c->rate_bps }} bps)
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-6 py-4 text-center text-zinc-400">No commission slabs defined.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Section 2: Closing Fee Slabs -->
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <div class="border-b border-zinc-100 bg-zinc-50/50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-800/30">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">2. Fixed Closing Fee Slabs</h3>
            <p class="text-xs text-zinc-500">Charged per unit sold based on unit selling price.</p>
        </div>
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="border-b border-zinc-200 bg-zinc-50/75 dark:border-zinc-800 dark:bg-zinc-800/40 text-zinc-500 font-semibold uppercase tracking-wider text-[11px]">
                    <th class="px-6 py-3">Selling Price Slab (INR)</th>
                    <th class="px-6 py-3 text-right">Fixed Fee Per Unit</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($sched->closingSlabs->sortBy('min_price') as $close)
                    <tr>
                        <td class="px-6 py-3 font-mono text-zinc-600 dark:text-zinc-400">
                            ₹{{ number_format($close->min_price / 100, 2) }} &rarr; {{ $close->max_price ? '₹'.number_format($close->max_price / 100, 2) : 'Above' }}
                        </td>
                        <td class="px-6 py-3 text-right font-mono font-bold text-zinc-900 dark:text-white">
                            ₹{{ number_format($close->fee_amount / 100, 2) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="px-6 py-4 text-center text-zinc-400">No closing fee slabs defined.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Section 3: Shipping Slabs -->
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <div class="border-b border-zinc-100 bg-zinc-50/50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-800/30">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">3. Shipping Slabs</h3>
            <p class="text-xs text-zinc-500">Charged on total package weight and delivery destination zone.</p>
        </div>
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="border-b border-zinc-200 bg-zinc-50/75 dark:border-zinc-800 dark:bg-zinc-800/40 text-zinc-500 font-semibold uppercase tracking-wider text-[11px]">
                    <th class="px-6 py-3">Zone</th>
                    <th class="px-6 py-3">Weight Slab (Grams)</th>
                    <th class="px-6 py-3">Default Fallback</th>
                    <th class="px-6 py-3 text-right">Shipping Fee</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($sched->shippingSlabs->sortBy(['zone', 'min_weight_grams']) as $ship)
                    <tr>
                        <td class="px-6 py-3 font-semibold text-zinc-900 dark:text-white uppercase text-[11px]">
                            {{ $ship->zone->label() }}
                        </td>
                        <td class="px-6 py-3 font-mono text-zinc-600 dark:text-zinc-400">
                            {{ number_format($ship->min_weight_grams) }}g &rarr; {{ $ship->max_weight_grams ? number_format($ship->max_weight_grams).'g' : 'Above' }}
                        </td>
                        <td class="px-6 py-3 text-zinc-500">
                            {{ $ship->is_default ? 'Yes (Default)' : 'No' }}
                        </td>
                        <td class="px-6 py-3 text-right font-mono font-bold text-zinc-900 dark:text-white">
                            ₹{{ number_format($ship->fee_amount / 100, 2) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-center text-zinc-400">No shipping slabs defined.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Section 4: Collection Rules -->
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <div class="border-b border-zinc-100 bg-zinc-50/50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-800/30">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">4. Payment Collection Rules</h3>
            <p class="text-xs text-zinc-500">Charged based on customer checkout payment method.</p>
        </div>
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="border-b border-zinc-200 bg-zinc-50/75 dark:border-zinc-800 dark:bg-zinc-800/40 text-zinc-500 font-semibold uppercase tracking-wider text-[11px]">
                    <th class="px-6 py-3">Payment Method</th>
                    <th class="px-6 py-3">Variable Rate</th>
                    <th class="px-6 py-3 text-right">Fixed Fee</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($sched->collectionRules as $rule)
                    <tr>
                        <td class="px-6 py-3 font-bold text-zinc-900 dark:text-white uppercase">
                            {{ $rule->payment_method }}
                        </td>
                        <td class="px-6 py-3 font-mono text-zinc-600 dark:text-zinc-400">
                            {{ number_format($rule->rate_bps / 100, 2) }}% ({{ $rule->rate_bps }} bps)
                        </td>
                        <td class="px-6 py-3 text-right font-mono font-bold text-zinc-900 dark:text-white">
                            ₹{{ number_format($rule->fee_amount / 100, 2) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-6 py-4 text-center text-zinc-400">No payment collection rules defined.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

