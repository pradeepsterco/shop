<?php

namespace App\Livewire\Admin;

use App\Fees\Contracts\FeeScheduleServiceInterface;
use App\Fees\Models\FeeSchedule;
use App\Services\AdminActivityLogger;
use App\Shared\Enums\FeeScheduleStatus;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Fee Schedules')] class extends Component {
    public ?int $activatingScheduleId = null;

    public bool $showActivateModal = false;

    public function cloneSchedule(int $scheduleId, FeeScheduleServiceInterface $service): void
    {
        $this->authorize('fees.manage');

        $schedule = FeeSchedule::findOrFail($scheduleId);
        $clone = $service->cloneSchedule($schedule);
        AdminActivityLogger::log('fee_schedule.cloned', $clone, [
            'source_id' => $schedule->id,
            'name' => $clone->name,
            'version' => $clone->version,
        ]);

        session()->flash('success', "Fee schedule '{$schedule->name}' cloned as Draft v{$clone->version}.");
        $this->redirect(route('admin.fees.edit', $clone), navigate: true);
    }

    public function confirmActivate(int $scheduleId): void
    {
        $this->authorize('fees.manage');

        $this->activatingScheduleId = $scheduleId;
        $this->showActivateModal = true;
    }

    public function activate(FeeScheduleServiceInterface $service): void
    {
        $this->authorize('fees.manage');

        if (! $this->activatingScheduleId) {
            return;
        }

        $schedule = FeeSchedule::findOrFail($this->activatingScheduleId);
        $service->activateSchedule($schedule);
        AdminActivityLogger::log('fee_schedule.activated', $schedule, [
            'name' => $schedule->name,
            'version' => $schedule->version,
        ]);

        $this->showActivateModal = false;
        $this->activatingScheduleId = null;

        session()->flash('success', "Fee schedule '{$schedule->name}' v{$schedule->version} is now Active! Any previous active schedule has been archived.");
    }
}; ?>

<div class="space-y-6">
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Fee Schedules</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Manage marketplace commission, closing fees, shipping weight slabs, and statutory tax rates.
            </p>
        </div>

        @can('fees.manage')
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.fees.create') }}"
                   wire:navigate
                   class="inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2.5 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    New Draft Schedule
                </a>
            </div>
        @endcan
    </div>

    <!-- Flash message -->
    @if (session()->has('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    <!-- Schedules Table -->
    @php
        $schedules = App\Fees\Models\FeeSchedule::orderByRaw("CASE WHEN status = 'active' THEN 1 WHEN status = 'draft' THEN 2 ELSE 3 END")
            ->orderByDesc('version')
            ->get();
    @endphp

    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <table class="w-full text-left border-collapse text-xs">
            <thead>
                <tr class="border-b border-zinc-200 bg-zinc-50/75 dark:border-zinc-800 dark:bg-zinc-800/40 text-zinc-600 dark:text-zinc-400 font-semibold uppercase tracking-wider text-[11px]">
                    <th class="px-6 py-3.5">Schedule</th>
                    <th class="px-6 py-3.5">Status</th>
                    <th class="px-6 py-3.5">Effective Period</th>
                    <th class="px-6 py-3.5">GST / TCS / TDS</th>
                    <th class="px-6 py-3.5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse ($schedules as $sched)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition">
                        <td class="px-6 py-4">
                            <div class="font-bold text-zinc-900 dark:text-white text-sm">
                                <a href="{{ route('admin.fees.show', $sched) }}" wire:navigate class="hover:underline">
                                    {{ $sched->name }}
                                </a>
                            </div>
                            <div class="text-[11px] text-zinc-400 font-mono mt-0.5">
                                Version {{ $sched->version }} &middot; Default weight: {{ $sched->default_weight_grams }}g
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wider {{ $sched->status->badgeClasses() }}">
                                {{ $sched->status->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-zinc-600 dark:text-zinc-400">
                            @if ($sched->effective_from)
                                <div>From: @datetime($sched->effective_from)</div>
                                <div>To: {{ $sched->effective_to ? format_datetime($sched->effective_to) : 'Ongoing (Current)' }}</div>
                            @else
                                <span class="italic text-zinc-400">Not yet activated</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 font-mono text-zinc-700 dark:text-zinc-300">
                            <div>GST: {{ $sched->gst_rate }}%</div>
                            <div>TCS: {{ $sched->tcs_rate }}% &middot; TDS: {{ $sched->tds_standard_rate }}% (No-PAN: {{ $sched->tds_no_pan_rate }}%)</div>
                        </td>
                        <td class="px-6 py-4 text-right space-x-2">
                            <a href="{{ route('admin.fees.show', $sched) }}"
                               wire:navigate
                               class="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                                View
                            </a>

                            @can('fees.manage')
                                @if ($sched->isDraft())
                                    <a href="{{ route('admin.fees.edit', $sched) }}"
                                       wire:navigate
                                       class="rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition">
                                        Edit
                                    </a>

                                    <button type="button"
                                            wire:click="confirmActivate({{ $sched->id }})"
                                            class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 transition">
                                        Activate
                                    </button>
                                @endif

                                <button type="button"
                                        wire:click="cloneSchedule({{ $sched->id }})"
                                        class="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                                    Clone
                                </button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-zinc-400">
                            No fee schedules created yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Activation Confirmation Modal -->
    @if ($this->showActivateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Activate Fee Schedule</h3>
                <p class="text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed">
                    Activating this schedule will set its status to <strong>Active</strong> starting now. Any currently active schedule will be automatically <strong>Archived</strong> at the exact same instant. Future order fee snapshots will use this new schedule.
                </p>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button"
                            wire:click="$set('showActivateModal', false)"
                            class="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                        Cancel
                    </button>
                    <button type="button"
                            wire:click="activate"
                            class="rounded-lg bg-emerald-600 px-4 py-1.5 text-xs font-bold text-white hover:bg-emerald-700 shadow-xs">
                        Yes, Activate Now
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

