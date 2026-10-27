<?php

namespace App\Livewire\Seller;

use App\Settlements\Contracts\SellerLedgerServiceInterface;
use App\Settlements\Contracts\SettlementServiceInterface;
use App\Settlements\DTOs\SellerBalanceDto;
use App\Settlements\DTOs\SettlementSummaryDto;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Settlements\Models\SellerPayout;
use App\Shared\ValueObjects\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.seller')] #[Title('Payments & Financials')] class extends Component {
    use WithPagination;

    public string $activeTab = 'settlements';

    public ?string $selectedSettlementId = null;

    public function mount(): void
    {
        if (! auth()->user()?->sellerProfile) {
            abort(403, 'Seller profile required.');
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function openBreakdown(string $settlementId): void
    {
        $this->selectedSettlementId = $settlementId;
    }

    public function closeBreakdown(): void
    {
        $this->selectedSettlementId = null;
    }

    #[Computed]
    public function sellerProfileId(): int
    {
        return (int) auth()->user()->sellerProfile->id;
    }

    #[Computed]
    public function balance(): SellerBalanceDto
    {
        return app(SellerLedgerServiceInterface::class)->getSellerBalance($this->sellerProfileId);
    }

    #[Computed]
    public function settlements(): LengthAwarePaginator
    {
        return SellerOrderSettlement::where('seller_profile_id', $this->sellerProfileId)
            ->orderByDesc('created_at')
            ->paginate(12, ['*'], 'settlements_page');
    }

    #[Computed]
    public function payouts(): LengthAwarePaginator
    {
        return SellerPayout::where('seller_profile_id', $this->sellerProfileId)
            ->with('batch')
            ->orderByDesc('created_at')
            ->paginate(12, ['*'], 'payouts_page');
    }

    #[Computed]
    public function selectedSettlement(): ?SellerOrderSettlement
    {
        if (! $this->selectedSettlementId) {
            return null;
        }

        return SellerOrderSettlement::where('seller_profile_id', $this->sellerProfileId)
            ->where('id', $this->selectedSettlementId)
            ->first();
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

    #[Computed]
    public function claimableCreditsTotal(): Money
    {
        $paise = (int) SellerOrderSettlement::where('seller_profile_id', $this->sellerProfileId)
            ->where('status', '!=', SettlementStatus::Void->value)
            ->sum('claimable_credits');

        return Money::fromPaise($paise);
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-black text-zinc-900 dark:text-white sm:text-2xl">
                Payments & Settlements
            </h1>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                Monitor live balances, fee deductions, escrow holding periods, and bank disbursements.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a
                href="{{ route('seller.payments.statement') }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-xs font-bold text-zinc-800 shadow-xs hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800 transition"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span>Tax Statement</span>
            </a>
            <a
                href="{{ route('seller.fees.calculator') }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-bold text-white shadow-xs hover:bg-emerald-700 transition"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                </svg>
                <span>Fee Calculator</span>
            </a>
        </div>
    </div>

    <!-- Balances 4-Card Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- On Hold -->
        <div class="rounded-2xl border border-amber-200 bg-amber-50/40 p-5 dark:border-amber-900/40 dark:bg-amber-950/20 shadow-xs">
            <div class="flex items-center justify-between text-xs font-bold uppercase tracking-wider text-amber-700 dark:text-amber-400">
                <span>On Hold (Escrow)</span>
                <span class="inline-flex size-2 rounded-full bg-amber-500"></span>
            </div>
            <div class="mt-2 text-2xl font-black text-amber-900 dark:text-amber-200" data-test="balance-on-hold">
                {{ $this->balance->onHoldMoney()->format() }}
            </div>
            <p class="mt-1 text-[11px] text-amber-700/80 dark:text-amber-400/80 leading-snug">
                Delivered orders within the 7-day customer return window.
            </p>
        </div>

        <!-- Eligible for Payout -->
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/40 p-5 dark:border-emerald-900/40 dark:bg-emerald-950/20 shadow-xs">
            <div class="flex items-center justify-between text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                <span>Eligible Payout</span>
                <span class="inline-flex size-2 rounded-full bg-emerald-500"></span>
            </div>
            <div class="mt-2 text-2xl font-black text-emerald-900 dark:text-emerald-200" data-test="balance-eligible">
                {{ $this->balance->eligibleMoney()->format() }}
            </div>
            <p class="mt-1 text-[11px] text-emerald-700/80 dark:text-emerald-400/80 leading-snug">
                Return window passed. Disbursed automatically on weekly payout day.
            </p>
        </div>

        <!-- In Payout Processing -->
        <div class="rounded-2xl border border-blue-200 bg-blue-50/40 p-5 dark:border-blue-900/40 dark:bg-blue-950/20 shadow-xs">
            <div class="flex items-center justify-between text-xs font-bold uppercase tracking-wider text-blue-700 dark:text-blue-400">
                <span>In Payout Batch</span>
                <span class="inline-flex size-2 rounded-full bg-blue-500"></span>
            </div>
            <div class="mt-2 text-2xl font-black text-blue-900 dark:text-blue-200" data-test="balance-in-payout">
                {{ $this->balance->inPayoutMoney()->format() }}
            </div>
            <p class="mt-1 text-[11px] text-blue-700/80 dark:text-blue-400/80 leading-snug">
                Queued in bank disbursement batch pending UTR confirmation.
            </p>
        </div>

        <!-- Lifetime Paid -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900 shadow-xs">
            <div class="flex items-center justify-between text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                <span>Lifetime Disbursed</span>
                <svg class="size-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <div class="mt-2 text-2xl font-black text-zinc-900 dark:text-white" data-test="balance-lifetime-paid">
                {{ $this->balance->lifetimePaidMoney()->format() }}
            </div>
            <p class="mt-1 text-[11px] text-zinc-500 leading-snug">
                Total net earnings successfully credited to your bank account.
            </p>
        </div>
    </div>

    <!-- Carried Negative Balance Warning (if any) -->
    @if ($this->balance->carriedNegativePaise > 0)
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-xs text-red-900 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200 flex items-start gap-3">
            <svg class="size-5 text-red-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <div>
                <strong class="font-bold">Carried Negative Balance: {{ $this->balance->carriedNegativeMoney()->format() }}</strong>
                <p class="text-[11px] text-red-700 dark:text-red-300 mt-0.5">
                    This balance arises from cancellations or customer returns post-disbursement and will be adjusted against upcoming eligible settlements.
                </p>
            </div>
        </div>
    @endif

    <!-- Tax Credits Summary Banner -->
    <div class="rounded-2xl border border-indigo-100 bg-gradient-to-r from-indigo-50/70 to-blue-50/70 p-5 dark:border-indigo-900/40 dark:from-indigo-950/30 dark:to-blue-950/30 shadow-xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-md bg-indigo-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-indigo-700 dark:bg-indigo-900/60 dark:text-indigo-300">
                    Compliant Tax Withholdings
                </span>
                <span class="text-xs font-bold text-zinc-900 dark:text-white">Claimable Direct & Indirect Tax Credits</span>
            </div>
            <p class="text-xs text-zinc-600 dark:text-zinc-400 mt-1">
                TCS & TDS deducted by {{ brand_name() }} are deposited directly with the Government and credit your GST Cash Ledger & Income Tax 26AS.
            </p>
        </div>
        <div class="text-right shrink-0">
            <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">Total Claimable Credits</span>
            <div class="text-xl font-black text-indigo-950 dark:text-indigo-200">
                {{ $this->claimableCreditsTotal->format() }}
            </div>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="flex items-center gap-3 border-b border-zinc-200 dark:border-zinc-800">
        <button
            type="button"
            wire:click="setTab('settlements')"
            class="pb-3 text-xs font-bold uppercase tracking-wider transition border-b-2 {{ $activeTab === 'settlements' ? 'border-emerald-600 text-emerald-600 dark:border-emerald-400 dark:text-emerald-400' : 'border-transparent text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
        >
            Order Settlements
        </button>
        <button
            type="button"
            wire:click="setTab('payouts')"
            class="pb-3 text-xs font-bold uppercase tracking-wider transition border-b-2 {{ $activeTab === 'payouts' ? 'border-emerald-600 text-emerald-600 dark:border-emerald-400 dark:text-emerald-400' : 'border-transparent text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
        >
            Payout History
        </button>
    </div>

    <!-- Tab 1: Settlements Table -->
    @if ($activeTab === 'settlements')
        <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-zinc-100 bg-zinc-50/70 text-zinc-400 font-bold dark:border-zinc-800 dark:bg-zinc-800/40">
                        <tr>
                            <th class="py-3 px-4">Order / Package ID</th>
                            <th class="py-3 px-3">Date</th>
                            <th class="py-3 px-3 text-right">Gross GMV</th>
                            <th class="py-3 px-3 text-right">Deductions</th>
                            <th class="py-3 px-3 text-right">Net Payable</th>
                            <th class="py-3 px-3">Status</th>
                            <th class="py-3 px-3">Escrow Release</th>
                            <th class="py-3 px-4 text-center">Breakdown</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($this->settlements as $settlement)
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                <td class="py-3 px-4 font-mono font-semibold text-zinc-900 dark:text-white">
                                    {{ $settlement->seller_order_id }}
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
                                        wire:click="openBreakdown('{{ $settlement->id }}')"
                                        class="rounded-lg border border-zinc-200 px-2.5 py-1 text-[11px] font-bold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                    >
                                        Inspect
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-8 text-center text-zinc-400">
                                    No order settlements found yet.
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
    @endif

    <!-- Tab 2: Payouts History Table -->
    @if ($activeTab === 'payouts')
        <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-zinc-100 bg-zinc-50/70 text-zinc-400 font-bold dark:border-zinc-800 dark:bg-zinc-800/40">
                        <tr>
                            <th class="py-3 px-4">Payout ID / Batch</th>
                            <th class="py-3 px-3">Date</th>
                            <th class="py-3 px-3">Bank Account</th>
                            <th class="py-3 px-3 text-right">Gross Amount</th>
                            <th class="py-3 px-3 text-right">Carried Deduction</th>
                            <th class="py-3 px-3 text-right">Disbursed Amount</th>
                            <th class="py-3 px-3">Status</th>
                            <th class="py-3 px-4">UTR Reference</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($this->payouts as $payout)
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                <td class="py-3 px-4 font-mono font-semibold text-zinc-900 dark:text-white">
                                    {{ $payout->batch?->batch_number ?? substr($payout->id, 0, 10) }}
                                </td>
                                <td class="py-3 px-3 text-zinc-500">
                                    @date($payout->created_at)
                                </td>
                                <td class="py-3 px-3 text-zinc-700 dark:text-zinc-300">
                                    <div>{{ $payout->bank_account_holder }}</div>
                                    <div class="text-[10px] text-zinc-400 font-mono">{{ $payout->bank_account_number_masked }} • {{ $payout->bank_ifsc }}</div>
                                </td>
                                <td class="py-3 px-3 text-right text-zinc-600 dark:text-zinc-400 font-medium">
                                    {{ $payout->getGrossSettlementMoney()->format() }}
                                </td>
                                <td class="py-3 px-3 text-right text-red-600 font-medium">
                                    @if ($payout->carried_balance_deduction > 0)
                                        -{{ $payout->getCarriedBalanceMoney()->format() }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-3 px-3 text-right font-black text-emerald-600 dark:text-emerald-400">
                                    {{ $payout->getNetAmountMoney()->format() }}
                                </td>
                                <td class="py-3 px-3">
                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-bold uppercase {{ match ($payout->status->value) {
                                        'paid' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
                                        'processing' => 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',
                                        'failed' => 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300',
                                        default => 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
                                    } }}">
                                        {{ $payout->status->label() }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 font-mono font-semibold text-zinc-800 dark:text-zinc-200">
                                    {{ $payout->utr ?? 'Pending Bank' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-8 text-center text-zinc-400">
                                    No payout disbursements processed yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
                {{ $this->payouts->links() }}
            </div>
        </div>
    @endif

    <!-- Itemized Breakdown & Ledger Inspector Modal -->
    @if ($this->selectedSettlement && $this->selectedSummary)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
            <div class="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-2xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 space-y-5 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between border-b border-zinc-100 pb-4 dark:border-zinc-800">
                    <div>
                        <h3 class="text-base font-black text-zinc-900 dark:text-white">
                            Settlement & Ledger Breakdown
                        </h3>
                        <p class="text-xs text-zinc-400 font-mono">
                            Package #{{ $this->selectedSettlement->seller_order_id }}
                        </p>
                    </div>
                    <button
                        type="button"
                        wire:click="closeBreakdown"
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
                        Double-Entry Ledger Audit
                    </h4>
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                        <table class="w-full text-left text-xs">
                            <thead class="border-b border-zinc-100 bg-zinc-50 text-zinc-400 font-bold dark:border-zinc-800 dark:bg-zinc-800">
                                <tr>
                                    <th class="py-2 px-3">Entry Type</th>
                                    <th class="py-2 px-3">Description</th>
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
                        wire:click="closeBreakdown"
                        class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-bold text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
