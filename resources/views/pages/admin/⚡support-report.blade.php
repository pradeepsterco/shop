<?php

namespace App\Livewire\Admin;

use App\Helpdesk\Models\SupportTicket;
use App\Helpdesk\Services\ComplaintTimelinesReport;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Complaint Timelines')] class extends Component {
    /** YYYY-MM */
    #[Url]
    public string $month = '';

    public function mount(): void
    {
        $this->authorize('viewAny', SupportTicket::class);

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function report(): array
    {
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')
            : CarbonImmutable::now();

        return app(ComplaintTimelinesReport::class)->forMonth($month ?: CarbonImmutable::now());
    }
}; ?>

@php
    $r = $this->report;
    $pct = fn (int $n) => $r['received'] > 0 ? round($n * 100 / $r['received']).'%' : '—';
    $ackHours = config('helpdesk.acknowledge_within_hours');
    $resolveDays = config('helpdesk.resolve_within_days');
@endphp

<div class="max-w-4xl space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="{{ route('admin.support.index') }}" wire:navigate class="text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white">&larr; Support inbox</a>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Complaint Timelines</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Customer requests received in the month, against the Consumer Protection (E-Commerce) Rules, 2020: acknowledge within {{ $ackHours }} hours, resolve within one month.
            </p>
        </div>
        <input type="month" wire:model.live="month" max="{{ now()->format('Y-m') }}"
               class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 focus:outline-none dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200" />
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Received</p>
            <p class="mt-2 text-3xl font-bold text-zinc-900 dark:text-white" data-test="report-received">{{ $r['received'] }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ format_date($r['from']) }} – {{ format_date($r['to']) }}</p>
        </div>
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Acknowledged within {{ $ackHours }}h</p>
            <p class="mt-2 text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ $pct($r['acknowledged']) }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Automatic email with the ticket number, sent on receipt</p>
        </div>
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Resolved within {{ $resolveDays }} days</p>
            <p @class(['mt-2 text-3xl font-bold', 'text-emerald-600 dark:text-emerald-400' => $r['resolved_late'] + $r['open_past_deadline'] === 0, 'text-rose-600 dark:text-rose-400' => $r['resolved_late'] + $r['open_past_deadline'] > 0]) data-test="report-resolved-pct">{{ $pct($r['resolved_within_deadline']) }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $r['resolved_within_deadline'] }} of {{ $r['received'] }}</p>
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <table class="w-full text-left text-sm">
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ([
                    'First staff reply within '.$ackHours.' hours' => [$r['staff_replied_within_ack'], null],
                    'Resolved within '.$resolveDays.' days' => [$r['resolved_within_deadline'], null],
                    'Resolved after '.$resolveDays.' days' => [$r['resolved_late'], 'late'],
                    'Still open, past '.$resolveDays.' days' => [$r['open_past_deadline'], 'late'],
                    'Still open, within '.$resolveDays.' days' => [$r['open_within_deadline'], null],
                ] as $label => [$count, $tone])
                    <tr>
                        <td class="px-5 py-3 text-zinc-700 dark:text-zinc-300">{{ $label }}</td>
                        <td @class(['px-5 py-3 text-right font-semibold', 'text-rose-600 dark:text-rose-400' => $tone === 'late' && $count > 0, 'text-zinc-900 dark:text-white' => ! ($tone === 'late' && $count > 0)])>{{ $count }}</td>
                        <td class="w-20 px-5 py-3 text-right text-xs text-zinc-500 dark:text-zinc-400">{{ $pct($count) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td class="px-5 py-3 text-zinc-700 dark:text-zinc-300">Median time to first staff reply</td>
                    <td class="px-5 py-3 text-right font-semibold text-zinc-900 dark:text-white" colspan="2">{{ $r['median_first_response_hours'] !== null ? $r['median_first_response_hours'].' h' : '—' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    @if ($r['open_past_deadline'] > 0)
        <a href="{{ route('admin.support.index', ['status' => 'overdue', 'requester' => 'customer']) }}" wire:navigate class="inline-block text-sm font-semibold text-rose-700 hover:underline dark:text-rose-400">See unresolved tickets over {{ $resolveDays }} days &rarr;</a>
    @endif
</div>
