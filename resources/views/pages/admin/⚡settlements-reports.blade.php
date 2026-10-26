<?php

namespace App\Livewire\Admin;

use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerOrderSettlement;
use App\Shared\ValueObjects\Money;
use App\Support\AdminPermissions;
use App\Support\DateFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Financial & Tax Liability Reports')] class extends Component {
    public string $groupBy = 'placed'; // 'placed' or 'delivered'

    public function boot(): void
    {
        Gate::authorize(AdminPermissions::SETTLEMENTS_VIEW);
    }

    public function setGroupBy(string $group): void
    {
        $this->groupBy = in_array($group, ['placed', 'delivered']) ? $group : 'placed';
    }

    /**
     * @return array<int, array{
     *     period: string,
     *     count: int,
     *     gross: Money,
     *     deductions: Money,
     *     net: Money,
     *     credits: Money
     * }>
     */
    #[Computed]
    public function monthlyReport(): array
    {
        // Query settlements where status != void
        // In sqlite and mysql, strftime or date formatting
        $dateColumn = $this->groupBy === 'delivered' ? 'hold_until' : 'created_at';

        $settlements = SellerOrderSettlement::where('status', '!=', SettlementStatus::Void->value)
            ->when($this->groupBy === 'delivered', fn ($q) => $q->whereNotNull('hold_until'))
            ->get();

        $grouped = $settlements->groupBy(function (SellerOrderSettlement $s) use ($dateColumn) {
            $val = $s->{$dateColumn};

            return $val ? DateFormatter::toDisplayTimezone($val)?->format('Y-m') ?? 'Unknown' : 'Unknown';
        });

        $report = [];
        foreach ($grouped as $period => $items) {
            $grossPaise = (int) $items->sum('gross_amount');
            $deductionsPaise = (int) $items->sum('total_deductions');
            $netPaise = (int) $items->sum('net_payout');
            $creditsPaise = (int) $items->sum('claimable_credits');

            $report[] = [
                'period' => $period,
                'count' => $items->count(),
                'gross' => Money::fromPaise($grossPaise),
                'deductions' => Money::fromPaise($deductionsPaise),
                'net' => Money::fromPaise($netPaise),
                'credits' => Money::fromPaise($creditsPaise),
            ];
        }

        // Sort descending by period
        usort($report, fn ($a, $b) => strcmp($b['period'], $a['period']));

        return $report;
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.settlements.index') }}" wire:navigate class="text-xs text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                    &larr; Settlements
                </a>
            </div>
            <h1 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-2xl mt-1">
                Platform Financial & Tax Liability Report
            </h1>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                Tax liabilities, GST TCS (Section 52), Income Tax TDS (Section 194-O), and marketplace revenues.
            </p>
        </div>

        <!-- Toggle Placed vs Delivered -->
        <div class="flex items-center rounded-xl border border-zinc-200 bg-white p-1 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <button
                type="button"
                wire:click="setGroupBy('placed')"
                class="rounded-lg px-3 py-1.5 text-xs font-bold transition {{ $groupBy === 'placed' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
            >
                Group by Placed Month
            </button>
            <button
                type="button"
                wire:click="setGroupBy('delivered')"
                class="rounded-lg px-3 py-1.5 text-xs font-bold transition {{ $groupBy === 'delivered' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
            >
                Group by Delivered Month
            </button>
        </div>
    </div>

    <!-- Monthly Summary Table -->
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-zinc-100 bg-zinc-50/70 text-zinc-400 font-bold dark:border-zinc-800 dark:bg-zinc-800/40">
                    <tr>
                        <th class="py-3 px-4">Accounting Period</th>
                        <th class="py-3 px-3 text-center">Settled Packages</th>
                        <th class="py-3 px-3 text-right">Gross GMV</th>
                        <th class="py-3 px-3 text-right">Marketplace Deductions</th>
                        <th class="py-3 px-3 text-right">Net Seller Disbursements</th>
                        <th class="py-3 px-4 text-right">Claimable Tax Credits</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($this->monthlyReport as $row)
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                            <td class="py-3 px-4 font-bold text-zinc-900 dark:text-white">
                                {{ Carbon\CarbonImmutable::createFromFormat('Y-m', $row['period'])->format('F Y') }}
                            </td>
                            <td class="py-3 px-3 text-center font-semibold text-zinc-700 dark:text-zinc-300">
                                {{ $row['count'] }}
                            </td>
                            <td class="py-3 px-3 text-right font-medium text-zinc-900 dark:text-white">
                                {{ $row['gross']->format() }}
                            </td>
                            <td class="py-3 px-3 text-right text-red-600 font-medium">
                                -{{ $row['deductions']->format() }}
                            </td>
                            <td class="py-3 px-3 text-right font-black text-emerald-600">
                                {{ $row['net']->format() }}
                            </td>
                            <td class="py-3 px-4 text-right font-bold text-indigo-600 dark:text-indigo-400">
                                {{ $row['credits']->format() }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-zinc-400">
                                No financial transactions found for the selected view.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

