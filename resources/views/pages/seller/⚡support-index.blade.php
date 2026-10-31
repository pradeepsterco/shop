<?php

namespace App\Livewire\Seller;

use App\Helpdesk\Enums\RequesterType;
use App\Helpdesk\Enums\TicketStatus;
use App\Helpdesk\Models\SupportTicket;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.seller')] #[Title('Seller Support')] class extends Component {
    use WithPagination;

    #[Computed]
    public function tickets(): LengthAwarePaginator
    {
        return SupportTicket::query()
            ->where('user_id', auth()->id())
            ->where('requester_type', RequesterType::Seller->value)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [TicketStatus::Pending->value])
            ->latest('last_message_at')
            ->paginate(15);
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl dark:text-white">Seller Support</h1>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Questions about orders, returns, payouts, fees, listings or your store account. Our team replies here and by email.</p>
        </div>
        <a href="{{ route('seller.support.create') }}" wire:navigate
           class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-amber-500 px-5 py-2.5 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-amber-600 transition">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            New request
        </a>
    </div>

    @if ($this->tickets->isEmpty())
        <div class="rounded-2xl border border-zinc-200 bg-white p-12 text-center shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="text-lg font-bold text-zinc-900 dark:text-white">No support requests yet</h2>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Stuck on an order, a payout or a listing? Start a request and we will get back to you.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($this->tickets as $ticket)
                    <li wire:key="ticket-{{ $ticket->id }}">
                        <a href="{{ route('seller.support.show', ['ticket' => $ticket->ticket_number]) }}" wire:navigate
                           class="flex flex-col gap-2 p-4 transition hover:bg-zinc-50 sm:flex-row sm:items-center sm:justify-between sm:p-5 dark:hover:bg-zinc-800/40">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-x-2 text-xs text-zinc-400">
                                    <span class="font-bold">{{ $ticket->ticket_number }}</span>
                                    <span>&middot; {{ $ticket->category->label() }}</span>
                                    @if ($ticket->order_number)
                                        <span>&middot; Order {{ $ticket->order_number }}</span>
                                    @endif
                                </div>
                                <p class="mt-0.5 flex items-center gap-2 text-sm text-zinc-900 dark:text-white">
                                    @if ($ticket->requester_unread)
                                        <span class="size-2 shrink-0 rounded-full bg-rose-500" title="New reply from support"></span>
                                    @endif
                                    <span @class(['truncate', 'font-bold' => $ticket->requester_unread, 'font-semibold' => ! $ticket->requester_unread])>{{ $ticket->subject }}</span>
                                    @if ($ticket->requester_unread)
                                        <span data-test="ticket-new-reply" class="shrink-0 rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">New reply</span>
                                    @endif
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $ticket->status->badgeClasses() }}">
                                    {{ $ticket->status->requesterLabel() }}
                                </span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">@datetime($ticket->last_message_at)</span>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div>
            {{ $this->tickets->links() }}
        </div>
    @endif
</div>
