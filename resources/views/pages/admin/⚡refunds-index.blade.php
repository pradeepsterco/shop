<?php

namespace App\Livewire\Admin;

use App\Orders\Contracts\CustomerRefundServiceInterface;
use App\Orders\Enums\RefundStatus;
use App\Orders\Models\CustomerRefund;
use App\Services\AdminActivityLogger;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Refunds')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = 'open';

    /** @var array<string, string> refund id => UTR being entered */
    public array $utr = [];

    /** @var array<string, string> refund id => failure reason being entered */
    public array $failureReason = [];

    public function mount(): void
    {
        $this->authorize('returns.view');
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function refunds()
    {
        return CustomerRefund::with('order')
            ->when($this->status === 'open', fn ($q) => $q->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Failed->value]))
            ->when($this->status !== 'open' && $this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(25);
    }

    public function markRefundPaid(string $refundId, CustomerRefundServiceInterface $refunds): void
    {
        $this->authorize('refunds.manage');

        try {
            $refund = $refunds->markPaid($refundId, (string) ($this->utr[$refundId] ?? ''), (int) auth()->id());

            // Never log payout details; the UTR is the bank's reference, not the customer's account
            AdminActivityLogger::log('refunds.paid', CustomerRefund::find($refund->id), [
                'amount_paise' => $refund->amountPaise,
                'utr' => strtoupper(trim((string) $this->utr[$refundId])),
            ]);

            unset($this->utr[$refundId]);
            session()->flash('refund_success', 'Refund marked paid; the customer has been notified.');
        } catch (\Throwable $e) {
            session()->flash('refund_error', 'Could not mark refund paid: '.$e->getMessage());
        }
    }

    public function markRefundFailed(string $refundId, CustomerRefundServiceInterface $refunds): void
    {
        $this->authorize('refunds.manage');

        try {
            $refund = $refunds->markFailed($refundId, (string) ($this->failureReason[$refundId] ?? ''), (int) auth()->id());

            AdminActivityLogger::log('refunds.failed', CustomerRefund::find($refund->id), [
                'amount_paise' => $refund->amountPaise,
                'reason' => trim((string) $this->failureReason[$refundId]),
            ]);

            unset($this->failureReason[$refundId]);
            session()->flash('refund_success', 'Refund marked failed; the customer has been asked to update their details.');
        } catch (\Throwable $e) {
            session()->flash('refund_error', 'Could not mark refund failed: '.$e->getMessage());
        }
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
            <h1 class="text-xl font-extrabold text-zinc-900 dark:text-white">Refunds</h1>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">Pay refunds manually to the customer's UPI or bank account, then record the UTR. Payout details are masked unless you can manage refunds.</p>
        </div>
        <select wire:model.live="status" data-test="refund-status-filter" class="rounded-lg border border-zinc-300 bg-white p-2 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
            <option value="open">Open (pending + failed)</option>
            @foreach (\App\Orders\Enums\RefundStatus::cases() as $case)
                <option value="{{ $case->value }}">{{ $case->label() }}</option>
            @endforeach
            <option value="all">All</option>
        </select>
    </div>

    @if (session('refund_success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300">{{ session('refund_success') }}</div>
    @endif
    @if (session('refund_error'))
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs font-semibold text-red-800 dark:border-red-900/50 dark:bg-red-950/50 dark:text-red-300">{{ session('refund_error') }}</div>
    @endif

    @php $canManage = (bool) auth('admin')->user()?->can('refunds.manage'); @endphp

    <div class="space-y-3">
        @forelse ($this->refunds as $refund)
            <div data-test="refund-row-{{ $refund->id }}" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs">
                    <div>
                        <span class="font-bold text-zinc-900 dark:text-white">Order {{ $refund->order?->order_number }}</span>
                        <span class="ml-2 text-zinc-500">₹{{ number_format($refund->amount_paise / 100, 2) }}</span>
                        <p class="text-zinc-500 dark:text-zinc-400 mt-0.5" data-test="refund-destination">{{ $refund->payoutDestination(full: $canManage) }}</p>
                    </div>
                    <div class="text-right">
                        <span class="rounded-lg bg-zinc-100 px-2.5 py-1 font-bold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $refund->status->label() }}</span>
                        @if ($refund->utr)
                            <p class="mt-1 font-mono text-zinc-500">UTR {{ $refund->utr }}</p>
                        @endif
                        @if ($refund->failure_reason)
                            <p class="mt-1 text-red-600 dark:text-red-400">{{ $refund->failure_reason }}</p>
                        @endif
                    </div>
                </div>

                @if ($canManage && $refund->status === \App\Orders\Enums\RefundStatus::Pending)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                        <div class="flex gap-2">
                            <input wire:model="utr.{{ $refund->id }}" type="text" placeholder="UTR / reference" class="flex-1 rounded-lg border border-zinc-300 bg-white p-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            <button type="button" wire:click="markRefundPaid('{{ $refund->id }}')" wire:confirm="Record this refund as paid? The customer will be notified." data-test="mark-refund-paid-btn" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700">Mark paid</button>
                        </div>
                        <div class="flex gap-2">
                            <input wire:model="failureReason.{{ $refund->id }}" type="text" placeholder="Why the payout failed" class="flex-1 rounded-lg border border-zinc-300 bg-white p-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            <button type="button" wire:click="markRefundFailed('{{ $refund->id }}')" wire:confirm="Record this payout as failed? The customer will be asked to update their details." data-test="mark-refund-failed-btn" class="rounded-lg border border-red-300 px-3 py-2 text-xs font-bold text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-300">Mark failed</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-xs text-zinc-500 dark:text-zinc-400">No refunds here.</p>
        @endforelse
    </div>

    {{ $this->refunds->links() }}
</div>
