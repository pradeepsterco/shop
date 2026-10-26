<?php

namespace App\Livewire\Admin;

use App\Settlements\Contracts\SellerLedgerServiceInterface;
use App\Settlements\Contracts\SettlementServiceInterface;
use App\Settlements\DTOs\SettlementSummaryDto;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Support\AdminPermissions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Settlements & Ledgers')] class extends Component {
    use WithPagination;

    public string $statusFilter = 'all';

    public string $search = '';

    public ?string $selectedSettlementId = null;

    public function boot(): void
    {
        Gate::authorize(AdminPermissions::SETTLEMENTS_VIEW);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function releaseMatured(SettlementServiceInterface $settlementService): void
    {
        Gate::authorize(AdminPermissions::PAYOUTS_MANAGE);

        $count = $settlementService->releaseMaturedSettlements();

        session()->flash('success', "Successfully evaluated and released {$count} matured settlement(s) into eligible status.");
    }

    public function inspectSettlement(string $id): void
    {
        $this->selectedSettlementId = $id;
    }

    public function closeInspection(): void
    {
        $this->selectedSettlementId = null;
    }

    #[Computed]
    public function settlements(): LengthAwarePaginator
    {
        $query = SellerOrderSettlement::query()->orderByDesc('created_at');

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if (trim($this->search) !== '') {
            $term = trim($this->search);
            $query->where(function ($q) use ($term) {
                $q->where('seller_order_id', 'like', "%{$term}%")
                    ->orWhere('order_id', 'like', "%{$term}%")
                    ->orWhere('seller_profile_id', 'like', "%{$term}%");
            });
        }

        return $query->paginate(15);
    }

    #[Computed]
    public function selectedSettlement(): ?SellerOrderSettlement
    {
        if (! $this->selectedSettlementId) {
            return null;
        }

        return SellerOrderSettlement::find($this->selectedSettlementId);
    }

    #[Computed]
    public function selectedSummary(): ?SettlementSummaryDto
    {
        $settlement = $this->selectedSettlement;
        if (! $settlement) {
            return null;
        }

        return app(SettlementServiceInterface::class)->getSettlementSummary($settlement);
    }

    /**
     * @return Collection<int, SellerLedgerEntry>
     */
    #[Computed]
    public function selectedLedgerEntries(): Collection
    {
        if (! $this->selectedSettlementId) {
            return new Collection;
        }

        return app(SellerLedgerServiceInterface::class)->getLedgerEntriesForSettlement($this->selectedSettlementId);
    }
}; ?>

<div class="space-y-6">
    <!-- Breadcrumb & Nav -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-2xl">
                Seller Settlements & Ledgers
            </h1>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                Monitor double-entry financial balances, escrow holding periods, fee breakdowns, and reversals.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a
                href="{{ route('admin.settlements.reports') }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-xs font-bold text-zinc-800 shadow-xs hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800 transition"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                <span>Tax & Revenue Reports</span>
            </a>

            @can(AdminPermissions::PAYOUTS_MANAGE)
                <button
                    type="button"
                    wire:click="releaseMatured"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-bold text-white shadow-xs hover:bg-emerald-700 transition"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    <span>Release Matured Holds</span>
                </button>
            @endcan
        </div>
    </div>

    <!-- Flash message -->
    @if (session()->has('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    <!-- Filters & Search -->
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 rounded-2xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="w-full sm:w-72">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search order ID or seller..."
                class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-1.5 text-xs text-zinc-900 focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
            />
        </div>

        <div class="flex items-center gap-2 overflow-x-auto w-full sm:w-auto">
            @foreach (['all' => 'All Statuses', 'pending' => 'Pending', 'on_hold' => 'On Hold', 'eligible' => 'Eligible', 'in_payout' => 'In Payout', 'paid' => 'Paid', 'void' => 'Void'] as $key => $label)
                <button
                    type="button"
                    wire:click="$set('statusFilter', '{{ $key }}')"
                    class="rounded-lg px-2.5 py-1 text-xs font-bold transition {{ $statusFilter === $key ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <!-- Settlements Table -->
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-zinc-100 bg-zinc-50/70 text-zinc-400 font-bold dark:border-zinc-800 dark:bg-zinc-800/40">
                    <tr>
                        <th class="py-3 px-4">Settlement ID / Package</th>
                        <th class="py-3 px-3">Seller #</th>
                        <th class="py-3 px-3">Date</th>
                        <th class="py-3 px-3 text-right">Gross GMV</th>
                        <th class="py-3 px-3 text-right">Deductions</th>
                        <th class="py-3 px-3 text-right">Net Payable</th>
                        <th class="py-3 px-3">Status</th>
                        <th class="py-3 px-3">Hold Until</th>
                        <th class="py-3 px-4 text-center">Inspect</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($this->settlements as $settlement)
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                            <td class="py-3 px-4 font-mono font-semibold text-zinc-900 dark:text-white">
                                <div>{{ $settlement->seller_order_id }}</div>
                                <div class="text-[10px] text-zinc-400 font-sans">Order: {{ $settlement->order_id }}</div>
                            </td>
                            <td class="py-3 px-3 text-zinc-700 dark:text-zinc-300">
                                Seller #{{ $settlement->seller_profile_id }}
                            </td>
                            <td class="py-3 px-3 text-zinc-500">
                                @date($settlement->created_at)
                            </td>
                            <td class="py-3 px-3 text-right font-medium text-zinc-900 dark:text-white">
                                {{ $settlement->getGrossMoney()->format() }}
                            </td>
                            <td class="py-3 px-3 text-right text-red-600 dark:text-red-400 font-medium">
                                -{{ $settlement->getDeductionsMoney()->format() }}
                            </td>
                            <td class="py-3 px-3 text-right font-black text-emerald-600 dark:text-emerald-400">
                                {{ $settlement->getNetPayoutMoney()->format() }}
                            </td>
                            <td class="py-3 px-3">
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-bold uppercase {{ match ($settlement->status->value) {
                                    'eligible' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
                                    'paid' => 'bg-purple-50 text-purple-700 dark:bg-purple-950/50 dark:text-purple-300',
                                    'in_payout' => 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',
                                    'on_hold' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
                                    'void' => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
                                    default => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
                                } }}">
                                    {{ $settlement->status->label() }}
                                </span>
                            </td>
                            <td class="py-3 px-3 text-[11px] text-zinc-500">
                                @if ($settlement->hold_until)
                                    @date($settlement->hold_until)
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-3 px-4 text-center">
                                <button
                                    type="button"
                                    wire:click="inspectSettlement('{{ $settlement->id }}')"
                                    class="rounded-lg border border-zinc-200 px-2.5 py-1 text-[11px] font-bold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                >
                                    Inspect
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-8 text-center text-zinc-400">
                                No settlements matched your search criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
            {{ $this->settlements->links() }}
        </div>
    </div>

    <!-- Inspector Modal -->
    @if ($this->selectedSettlement && $this->selectedSummary)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
            <div class="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-2xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 space-y-5 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between border-b border-zinc-100 pb-4 dark:border-zinc-800">
                    <div>
                        <h3 class="text-base font-black text-zinc-900 dark:text-white">
                            Double-Entry Ledger Audit
                        </h3>
                        <p class="text-xs text-zinc-400 font-mono">
                            Package #{{ $this->selectedSettlement->seller_order_id }} &bull; Seller #{{ $this->selectedSettlement->seller_profile_id }}
                        </p>
                    </div>
                    <button
                        type="button"
                        wire:click="closeInspection"
                        class="size-8 rounded-lg text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 flex items-center justify-center"
                    >
                        &times;
                    </button>
                </div>

                <!-- Financial Summary Grid -->
                <div class="grid grid-cols-3 gap-3 rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/50 text-center">
                    <div>
                        <span class="text-[10px] font-bold uppercase text-zinc-400">Gross Sale GMV</span>
                        <div class="text-sm font-black text-zinc-900 dark:text-white">
                            {{ $this->selectedSummary->grossMoney()->format() }}
                        </div>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold uppercase text-red-500">Total Deductions</span>
                        <div class="text-sm font-black text-red-600">
                            -{{ $this->selectedSummary->deductionsMoney()->format() }}
                        </div>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold uppercase text-emerald-600">Net Payable</span>
                        <div class="text-sm font-black text-emerald-600">
                            {{ $this->selectedSummary->netMoney()->format() }}
                        </div>
                    </div>
                </div>

                <!-- Ledger Entries Table -->
                <div class="space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                        Ledger Journal Entries
                    </h4>
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                        <table class="w-full text-left text-xs">
                            <thead class="border-b border-zinc-100 bg-zinc-50 text-zinc-400 font-bold dark:border-zinc-800 dark:bg-zinc-800">
                                <tr>
                                    <th class="py-2 px-3">Entry Type</th>
                                    <th class="py-2 px-3">Description</th>
                                    <th class="py-2 px-3">Source Key</th>
                                    <th class="py-2 px-3 text-right">Amount (₹)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 font-mono text-[11px]">
                                @foreach ($this->selectedLedgerEntries as $entry)
                                    <tr>
                                        <td class="py-2 px-3 font-semibold text-zinc-800 dark:text-zinc-200">
                                            {{ $entry->type->label() }}
                                        </td>
                                        <td class="py-2 px-3 text-zinc-500 font-sans">
                                            {{ $entry->description }}
                                        </td>
                                        <td class="py-2 px-3 text-zinc-400 text-[10px]">
                                            {{ $entry->source_key }}
                                        </td>
                                        <td class="py-2 px-3 text-right font-black {{ $entry->amount >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                            {{ $entry->amount >= 0 ? '+' : '' }}{{ number_format($entry->amount / 100, 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <button
                        type="button"
                        wire:click="closeInspection"
                        class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-bold text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

