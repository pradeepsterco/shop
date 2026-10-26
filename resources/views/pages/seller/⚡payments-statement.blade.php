<?php

namespace App\Livewire\Seller;

use App\Settlements\Enums\LedgerEntryType;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.seller')] #[Title('Monthly Tax Statement')] class extends Component {
    public int $year;

    public int $month;

    public function mount(): void
    {
        if (! auth()->user()?->sellerProfile) {
            abort(403, 'Seller profile required.');
        }

        $this->year = (int) now()->year;
        $this->month = (int) now()->month;
    }

    #[Computed]
    public function sellerProfileId(): int
    {
        return (int) auth()->user()->sellerProfile->id;
    }

    #[Computed]
    public function startDate(): CarbonImmutable
    {
        $tz = (string) config('app.display_timezone', 'Asia/Kolkata');

        return CarbonImmutable::createFromDate($this->year, $this->month, 1, $tz)->startOfMonth()->utc();
    }

    #[Computed]
    public function endDate(): CarbonImmutable
    {
        $tz = (string) config('app.display_timezone', 'Asia/Kolkata');

        return CarbonImmutable::createFromDate($this->year, $this->month, 1, $tz)->endOfMonth()->utc();
    }

    /**
     * @return array<string, Money>
     */
    #[Computed]
    public function aggregates(): array
    {
        $settlements = SellerOrderSettlement::where('seller_profile_id', $this->sellerProfileId)
            ->where('status', '!=', SettlementStatus::Void->value)
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->get();

        $grossPaise = (int) $settlements->sum('gross_amount');
        $deductionsPaise = (int) $settlements->sum('total_deductions');
        $netPaise = (int) $settlements->sum('net_payout');
        $creditsPaise = (int) $settlements->sum('claimable_credits');

        $ledgerEntries = SellerLedgerEntry::where('seller_profile_id', $this->sellerProfileId)
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->where('type', '!=', LedgerEntryType::Reversal->value)
            ->get();

        $commission = abs((int) $ledgerEntries->where('type', LedgerEntryType::Commission)->sum('amount'));
        $closing = abs((int) $ledgerEntries->where('type', LedgerEntryType::ClosingFee)->sum('amount'));
        $shipping = abs((int) $ledgerEntries->where('type', LedgerEntryType::ShippingFee)->sum('amount'));
        $collection = abs((int) $ledgerEntries->where('type', LedgerEntryType::CollectionFee)->sum('amount'));
        $gstOnFees = abs((int) $ledgerEntries->where('type', LedgerEntryType::GstOnFees)->sum('amount'));
        $tcs = abs((int) $ledgerEntries->where('type', LedgerEntryType::Tcs)->sum('amount'));
        $tds = abs((int) $ledgerEntries->where('type', LedgerEntryType::Tds)->sum('amount'));

        return [
            'gross' => Money::fromPaise($grossPaise),
            'deductions' => Money::fromPaise($deductionsPaise),
            'net' => Money::fromPaise($netPaise),
            'credits' => Money::fromPaise($creditsPaise),
            'commission' => Money::fromPaise($commission),
            'closing' => Money::fromPaise($closing),
            'shipping' => Money::fromPaise($shipping),
            'collection' => Money::fromPaise($collection),
            'gstOnFees' => Money::fromPaise($gstOnFees),
            'tcs' => Money::fromPaise($tcs),
            'tds' => Money::fromPaise($tds),
        ];
    }
}; ?>

<div class="space-y-6">
    <!-- Breadcrumb & Back -->
    <div class="flex items-center justify-between">
        <a href="{{ route('seller.payments.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-semibold text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition">
            &larr; Back to Payments
        </a>
        <button type="button" onclick="window.print()" class="rounded-xl border border-zinc-200 bg-white px-3 py-1.5 text-xs font-bold text-zinc-800 shadow-xs hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 print:hidden">
            Print Statement
        </button>
    </div>

    <!-- Header & Period Filter -->
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Monthly Financial Statement</span>
            <h1 class="text-xl font-black text-zinc-900 dark:text-white mt-1">
                {{ $this->startDate->format('F Y') }} Statement
            </h1>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                Store: <strong>{{ auth()->user()->sellerProfile->store_name }}</strong> &bull; GSTIN: {{ auth()->user()->sellerProfile->gstin ?? 'Unregistered' }}
            </p>
        </div>

        <div class="flex items-center gap-2 print:hidden">
            <select
                wire:model.live="month"
                class="rounded-xl border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900 dark:text-white"
            >
                @for ($m = 1; $m <= 12; $m++)
                    <option value="{{ $m }}">{{ Carbon\CarbonImmutable::createFromDate(2026, $m, 1)->format('F') }}</option>
                @endfor
            </select>

            <select
                wire:model.live="year"
                class="rounded-xl border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900 dark:text-white"
            >
                @foreach ([2025, 2026, 2027] as $y)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <!-- Statement Table -->
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden p-6 space-y-6">
        <h2 class="text-sm font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-400 border-b border-zinc-100 pb-2 dark:border-zinc-800">
            Account Summary & Deductions
        </h2>

        <div class="divide-y divide-zinc-100 dark:divide-zinc-800 text-xs">
            <div class="flex justify-between py-2.5 font-bold text-zinc-900 dark:text-white">
                <span>Gross Marketplace Sales (GMV)</span>
                <span>{{ $this->aggregates['gross']->format() }}</span>
            </div>

            <!-- Deductions Sub-items -->
            <div class="py-2.5 space-y-2 text-zinc-600 dark:text-zinc-400 pl-4">
                <div class="flex justify-between">
                    <span>Marketplace Commission</span>
                    <span class="text-red-600">-{{ $this->aggregates['commission']->format() }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Fixed Closing Fees</span>
                    <span class="text-red-600">-{{ $this->aggregates['closing']->format() }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Shipping & Logistics Fees</span>
                    <span class="text-red-600">-{{ $this->aggregates['shipping']->format() }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Payment Gateway Collection Fees</span>
                    <span class="text-red-600">-{{ $this->aggregates['collection']->format() }}</span>
                </div>
                <div class="flex justify-between">
                    <span>GST on Marketplace Fees (18%)</span>
                    <span class="text-red-600">-{{ $this->aggregates['gstOnFees']->format() }}</span>
                </div>
                <div class="flex justify-between">
                    <span>GST TCS (Sec 52)</span>
                    <span class="text-red-600">-{{ $this->aggregates['tcs']->format() }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Income Tax TDS (Sec 194-O)</span>
                    <span class="text-red-600">-{{ $this->aggregates['tds']->format() }}</span>
                </div>
            </div>

            <div class="flex justify-between py-3 font-black text-sm text-zinc-900 dark:text-white">
                <span>Net Settled Amount</span>
                <span class="text-emerald-600">{{ $this->aggregates['net']->format() }}</span>
            </div>
        </div>

        <!-- Tax Claim Section -->
        <div class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/40 space-y-2">
            <h3 class="text-xs font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-300">
                Tax Credits Breakdown (For Your CA / GST Filing)
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs pt-1">
                <div class="border-r border-zinc-200 dark:border-zinc-700 pr-2">
                    <span class="text-zinc-500">GST Input Credit (ITC)</span>
                    <div class="font-bold text-zinc-900 dark:text-white mt-0.5">{{ $this->aggregates['gstOnFees']->format() }}</div>
                    <p class="text-[10px] text-zinc-400">Claim via GSTR-2B</p>
                </div>
                <div class="border-r border-zinc-200 dark:border-zinc-700 pr-2">
                    <span class="text-zinc-500">GST TCS Credit</span>
                    <div class="font-bold text-zinc-900 dark:text-white mt-0.5">{{ $this->aggregates['tcs']->format() }}</div>
                    <p class="text-[10px] text-zinc-400">Credits GST Cash Ledger</p>
                </div>
                <div>
                    <span class="text-zinc-500">Income Tax TDS</span>
                    <div class="font-bold text-zinc-900 dark:text-white mt-0.5">{{ $this->aggregates['tds']->format() }}</div>
                    <p class="text-[10px] text-zinc-400">Available on Form 26AS</p>
                </div>
            </div>
        </div>
    </div>
</div>

