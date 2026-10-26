<?php

namespace App\Livewire\Admin;

use App\Settlements\Contracts\PayoutServiceInterface;
use App\Settlements\DTOs\PayoutBatchCreateInput;
use App\Settlements\DTOs\PayoutBatchResultDto;
use App\Settlements\Models\SellerPayout;
use App\Settlements\Models\SellerPayoutBatch;
use App\Support\AdminPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.admin')] #[Title('Payout Batches & Disbursements')] class extends Component {
    use WithPagination;

    public bool $showCreateModal = false;

    public string $scheduledDate = '';

    public string $batchNotes = '';

    public ?string $selectedBatchId = null;

    // Mark paid modal
    public bool $showMarkPaidModal = false;

    public ?string $markingPayoutId = null;

    public string $utrNumber = '';

    // Mark failed modal
    public bool $showMarkFailedModal = false;

    public ?string $failingPayoutId = null;

    public string $failureReason = '';

    /**
     * @var array<int, array{seller_id: int, business_name: string, reason: string}>
     */
    public array $lastBatchExcluded = [];

    /**
     * @var array<int, array{seller_id: int, business_name: string, reason: string}>
     */
    public array $lastBatchManualReview = [];

    public function boot(): void
    {
        Gate::authorize(AdminPermissions::PAYOUTS_VIEW);
    }

    public function mount(): void
    {
        $this->scheduledDate = now()->toDateString();
    }

    public function openCreateModal(): void
    {
        Gate::authorize(AdminPermissions::PAYOUTS_MANAGE);
        $this->showCreateModal = true;
    }

    public function createBatch(PayoutServiceInterface $payoutService): void
    {
        Gate::authorize(AdminPermissions::PAYOUTS_MANAGE);

        $this->validate([
            'scheduledDate' => ['required', 'date'],
        ]);

        try {
            $input = new PayoutBatchCreateInput(
                scheduledPayoutDate: CarbonImmutable::parse($this->scheduledDate),
                notes: $this->batchNotes ?: null,
                createdByAdminId: (int) auth('admin')->id(),
            );

            /** @var PayoutBatchResultDto $result */
            $result = $payoutService->createWeeklyPayoutBatch($input);

            $this->lastBatchExcluded = $result->excludedSellers;
            $this->lastBatchManualReview = $result->manualReviewSellers;
            $this->showCreateModal = false;

            if ($result->batch) {
                $this->selectedBatchId = $result->batch->id;
                session()->flash('success', "Created payout batch #{$result->batch->batch_number} with {$result->payoutCount} payout(s) totaling {$result->batch->getTotalAmountMoney()->format()}.");
            } else {
                session()->flash('warning', 'No eligible payouts found for this batch. All pending sellers were either below threshold, pending verification, or already assigned.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function downloadBankCsv(string $batchId, PayoutServiceInterface $payoutService): StreamedResponse
    {
        Gate::authorize(AdminPermissions::PAYOUTS_VIEW);

        try {
            $batch = SellerPayoutBatch::findOrFail($batchId);
            $csvContent = $payoutService->generateBankCsv($batchId);

            $filename = "payout-batch-{$batch->batch_number}.csv";

            return response()->streamDownload(function () use ($csvContent) {
                echo $csvContent;
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());

            return response()->streamDownload(fn () => null, 'error.txt');
        }
    }

    public function openMarkPaidModal(string $payoutId): void
    {
        Gate::authorize(AdminPermissions::PAYOUTS_MANAGE);
        $this->markingPayoutId = $payoutId;
        $this->utrNumber = '';
        $this->showMarkPaidModal = true;
    }

    public function confirmMarkPaid(PayoutServiceInterface $payoutService): void
    {
        Gate::authorize(AdminPermissions::PAYOUTS_MANAGE);

        $this->validate([
            'utrNumber' => ['required', 'string', 'min:6', 'max:50'],
        ]);

        if (! $this->markingPayoutId) {
            return;
        }

        try {
            $payoutService->markPayoutPaid($this->markingPayoutId, $this->utrNumber);

            session()->flash('success', "Payout marked as Paid with UTR {$this->utrNumber}.");
            $this->showMarkPaidModal = false;
            $this->markingPayoutId = null;
            $this->utrNumber = '';
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openMarkFailedModal(string $payoutId): void
    {
        Gate::authorize(AdminPermissions::PAYOUTS_MANAGE);
        $this->failingPayoutId = $payoutId;
        $this->failureReason = '';
        $this->showMarkFailedModal = true;
    }

    public function confirmMarkFailed(PayoutServiceInterface $payoutService): void
    {
        Gate::authorize(AdminPermissions::PAYOUTS_MANAGE);

        $this->validate([
            'failureReason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        if (! $this->failingPayoutId) {
            return;
        }

        try {
            $payoutService->markPayoutFailed($this->failingPayoutId, $this->failureReason);

            session()->flash('success', 'Payout marked as Failed. Underlying settlements reverted to eligible.');
            $this->showMarkFailedModal = false;
            $this->failingPayoutId = null;
            $this->failureReason = '';
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelBatch(string $batchId, PayoutServiceInterface $payoutService): void
    {
        Gate::authorize(AdminPermissions::PAYOUTS_MANAGE);

        try {
            $payoutService->cancelBatch($batchId);
            session()->flash('success', 'Batch successfully cancelled. All associated settlements reverted to eligible.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function selectBatch(string $batchId): void
    {
        $this->selectedBatchId = $this->selectedBatchId === $batchId ? null : $batchId;
    }

    #[Computed]
    public function batches(): LengthAwarePaginator
    {
        return SellerPayoutBatch::orderByDesc('created_at')->paginate(10);
    }

    #[Computed]
    public function selectedBatch(): ?SellerPayoutBatch
    {
        if (! $this->selectedBatchId) {
            return null;
        }

        return SellerPayoutBatch::with('payouts')->find($this->selectedBatchId);
    }

    /**
     * @return Collection<int, SellerPayout>
     */
    #[Computed]
    public function selectedBatchPayouts(): Collection
    {
        if (! $this->selectedBatchId) {
            return new Collection;
        }

        return SellerPayout::where('payout_batch_id', $this->selectedBatchId)
            ->orderBy('id')
            ->get();
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-2xl">
                Weekly Payout Batches
            </h1>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                Generate bank transfer files, review bank verification fingerprints, and reconcile disbursement UTRs.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a
                href="{{ route('admin.settlements.index') }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-xs font-bold text-zinc-800 shadow-xs hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800 transition"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>View Settlements</span>
            </a>

            @can(AdminPermissions::PAYOUTS_MANAGE)
                <button
                    type="button"
                    wire:click="openCreateModal"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-purple-600 px-3.5 py-2 text-xs font-bold text-white shadow-xs hover:bg-purple-700 transition"
                    data-test="create-payout-batch-button"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    <span>Create Weekly Batch</span>
                </button>
            @endcan
        </div>
    </div>

    <!-- Alerts -->
    @if (session()->has('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif
    @if (session()->has('warning'))
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs font-semibold text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/50 dark:text-amber-300">
            {{ session('warning') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs font-semibold text-red-800 dark:border-red-900/50 dark:bg-red-950/50 dark:text-red-300">
            {{ session('error') }}
        </div>
    @endif

    <!-- Excluded Sellers Summary Box (if present from batch creation) -->
    @if (! empty($lastBatchExcluded) || ! empty($lastBatchManualReview))
        <div class="rounded-2xl border border-amber-200 bg-amber-50/60 p-5 dark:border-amber-900/50 dark:bg-amber-950/30 space-y-3">
            <h3 class="text-xs font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300">
                Batch Generation Seller Exclusions & Review Alerts
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                @if (! empty($lastBatchExcluded))
                    <div>
                        <span class="font-bold text-amber-900 dark:text-amber-200">Excluded Sellers (Held for Verification or Threshold):</span>
                        <ul class="mt-1 list-disc list-inside space-y-1 text-amber-800 dark:text-amber-300">
                            @foreach ($lastBatchExcluded as $ex)
                                <li>
                                    <strong>{{ $ex['business_name'] }}</strong> (ID: {{ $ex['seller_id'] }}): <span class="italic">{{ $ex['reason'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if (! empty($lastBatchManualReview))
                    <div>
                        <span class="font-bold text-red-900 dark:text-red-200">Manual Review Required:</span>
                        <ul class="mt-1 list-disc list-inside space-y-1 text-red-800 dark:text-red-300">
                            @foreach ($lastBatchManualReview as $mr)
                                <li>
                                    <strong>{{ $mr['business_name'] }}</strong>: {{ $mr['reason'] }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Batches Table -->
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-zinc-100 bg-zinc-50/70 text-zinc-400 font-bold dark:border-zinc-800 dark:bg-zinc-800/40">
                    <tr>
                        <th class="py-3 px-4">Batch Number</th>
                        <th class="py-3 px-3">Cutoff Date</th>
                        <th class="py-3 px-3">Sellers</th>
                        <th class="py-3 px-3 text-right">Total Disbursement</th>
                        <th class="py-3 px-3">Status</th>
                        <th class="py-3 px-3">Created</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($this->batches as $batch)
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 {{ $selectedBatchId === $batch->id ? 'bg-purple-50/40 dark:bg-purple-950/20' : '' }}">
                            <td class="py-3 px-4 font-mono font-bold text-zinc-900 dark:text-white">
                                {{ $batch->batch_number }}
                            </td>
                            <td class="py-3 px-3 text-zinc-600 dark:text-zinc-400">
                                @date($batch->cutoff_date)
                            </td>
                            <td class="py-3 px-3 font-semibold text-zinc-900 dark:text-white">
                                {{ $batch->total_sellers_count }} sellers
                            </td>
                            <td class="py-3 px-3 text-right font-black text-purple-600 dark:text-purple-400">
                                {{ $batch->getTotalAmountMoney()->format() }}
                            </td>
                            <td class="py-3 px-3">
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-bold uppercase {{ match ($batch->status) {
                                    'completed' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
                                    'processing' => 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',
                                    'cancelled' => 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300',
                                    default => 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
                                } }}">
                                    {{ ucfirst($batch->status) }}
                                </span>
                            </td>
                            <td class="py-3 px-3 text-zinc-500">
                                @datetime($batch->created_at)
                            </td>
                            <td class="py-3 px-4 text-right space-x-2">
                                <button
                                    type="button"
                                    wire:click="selectBatch('{{ $batch->id }}')"
                                    class="rounded-lg border border-zinc-200 px-2.5 py-1 text-[11px] font-bold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                >
                                    {{ $selectedBatchId === $batch->id ? 'Hide Details' : 'View Payouts' }}
                                </button>

                                <button
                                    type="button"
                                    wire:click="downloadBankCsv('{{ $batch->id }}')"
                                    class="rounded-lg bg-zinc-900 px-2.5 py-1 text-[11px] font-bold text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900"
                                >
                                    Bank CSV
                                </button>

                                @can(AdminPermissions::PAYOUTS_MANAGE)
                                    @if ($batch->status !== 'completed' && $batch->status !== 'cancelled')
                                        <button
                                            type="button"
                                            wire:click="cancelBatch('{{ $batch->id }}')"
                                            wire:confirm="Cancel this batch and return all settlements to eligible status?"
                                            class="rounded-lg border border-red-200 px-2.5 py-1 text-[11px] font-bold text-red-600 hover:bg-red-50 dark:border-red-900/50 dark:text-red-400"
                                        >
                                            Cancel
                                        </button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-zinc-400">
                                No payout batches found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
            {{ $this->batches->links() }}
        </div>
    </div>

    <!-- Payout Items Section for Selected Batch -->
    @if ($this->selectedBatch)
        <div class="rounded-2xl border border-purple-200 bg-white p-6 shadow-sm dark:border-purple-900/50 dark:bg-zinc-900 space-y-4">
            <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                <div>
                    <span class="text-xs font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400">Selected Batch Payouts</span>
                    <h2 class="text-lg font-black text-zinc-900 dark:text-white">
                        {{ $this->selectedBatch->batch_number }} &bull; {{ $this->selectedBatch->total_sellers_count }} Beneficiaries
                    </h2>
                </div>
                <button
                    type="button"
                    wire:click="$set('selectedBatchId', null)"
                    class="text-xs font-semibold text-zinc-400 hover:text-zinc-700"
                >
                    Close
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-zinc-100 bg-zinc-50/70 text-zinc-400 font-bold dark:border-zinc-800 dark:bg-zinc-800/40">
                        <tr>
                            <th class="py-2.5 px-3">Seller #</th>
                            <th class="py-2.5 px-3">Bank Details</th>
                            <th class="py-2.5 px-3 text-right">Gross GMV</th>
                            <th class="py-2.5 px-3 text-right">Carried Deduction</th>
                            <th class="py-2.5 px-3 text-right">Net Payable</th>
                            <th class="py-2.5 px-3">Status</th>
                            <th class="py-2.5 px-3">UTR Reference</th>
                            <th class="py-2.5 px-3 text-right">Reconcile</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->selectedBatchPayouts as $payout)
                            <tr>
                                <td class="py-2.5 px-3 font-semibold text-zinc-900 dark:text-white">
                                    Seller #{{ $payout->seller_profile_id }}
                                </td>
                                <td class="py-2.5 px-3 text-zinc-700 dark:text-zinc-300">
                                    <div class="font-bold">{{ $payout->bank_account_holder }}</div>
                                    <div class="text-[10px] text-zinc-400 font-mono">{{ $payout->bank_account_number_masked }} &bull; {{ $payout->bank_ifsc }}</div>
                                </td>
                                <td class="py-2.5 px-3 text-right text-zinc-600 dark:text-zinc-400">
                                    {{ $payout->getGrossSettlementMoney()->format() }}
                                </td>
                                <td class="py-2.5 px-3 text-right text-red-600">
                                    @if ($payout->carried_balance_deduction > 0)
                                        -{{ $payout->getCarriedBalanceMoney()->format() }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2.5 px-3 text-right font-black text-emerald-600">
                                    {{ $payout->getNetAmountMoney()->format() }}
                                </td>
                                <td class="py-2.5 px-3">
                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-bold uppercase {{ match ($payout->status->value) {
                                        'paid' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
                                        'failed' => 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300',
                                        default => 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
                                    } }}">
                                        {{ $payout->status->label() }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 font-mono font-semibold text-zinc-800 dark:text-zinc-200">
                                    {{ $payout->utr ?? '—' }}
                                </td>
                                <td class="py-2.5 px-3 text-right space-x-1">
                                    @can(AdminPermissions::PAYOUTS_MANAGE)
                                        @if ($payout->status->value !== 'paid')
                                            <button
                                                type="button"
                                                wire:click="openMarkPaidModal('{{ $payout->id }}')"
                                                class="rounded-lg bg-emerald-600 px-2 py-1 text-[10px] font-bold text-white hover:bg-emerald-700"
                                            >
                                                Mark Paid
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="openMarkFailedModal('{{ $payout->id }}')"
                                                class="rounded-lg border border-red-200 px-2 py-1 text-[10px] font-bold text-red-600 hover:bg-red-50"
                                            >
                                                Mark Failed
                                            </button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Create Batch Modal -->
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 space-y-4">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Create Weekly Payout Batch</h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    This will assign all matured, eligible settlements to a new draft batch. Sellers with pending bank verification or below minimum threshold (₹1,000) will remain in eligible status.
                </p>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-zinc-500 mb-1">Cutoff Date</label>
                        <input
                            type="date"
                            wire:model="scheduledDate"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-purple-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />
                        @error('scheduledDate') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-zinc-500 mb-1">Batch Notes (Optional)</label>
                        <textarea
                            wire:model="batchNotes"
                            rows="2"
                            placeholder="e.g. Regular Wednesday weekly settlement disbursement"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-purple-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        ></textarea>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="$set('showCreateModal', false)"
                        class="rounded-xl border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="createBatch"
                        class="rounded-xl bg-purple-600 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white hover:bg-purple-700"
                    >
                        Generate Batch
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Mark Paid Modal -->
    @if ($showMarkPaidModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 space-y-4">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Record UTR Reference</h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Enter the Unique Transaction Reference (UTR) provided by the bank after processing NEFT/RTGS.
                </p>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-zinc-500 mb-1">Bank UTR <span class="text-red-500">*</span></label>
                    <input
                        type="text"
                        wire:model="utrNumber"
                        placeholder="e.g. HDFC202610250001"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs font-mono text-zinc-900 focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                    />
                    @error('utrNumber') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="$set('showMarkPaidModal', false)"
                        class="rounded-xl border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="confirmMarkPaid"
                        class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white hover:bg-emerald-700"
                    >
                        Confirm Payment
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Mark Failed Modal -->
    @if ($showMarkFailedModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 space-y-4">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Mark Payout as Failed</h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Record bank rejection reason. The underlying settlements will revert to eligible status for subsequent re-disbursement.
                </p>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-zinc-500 mb-1">Failure Reason <span class="text-red-500">*</span></label>
                    <input
                        type="text"
                        wire:model="failureReason"
                        placeholder="e.g. Invalid Account Number / IFSC mismatch"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-red-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                    />
                    @error('failureReason') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="$set('showMarkFailedModal', false)"
                        class="rounded-xl border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="confirmMarkFailed"
                        class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white hover:bg-red-700"
                    >
                        Confirm Failure
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

