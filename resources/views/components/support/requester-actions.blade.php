@props([
    'ticket',
    // The Livewire component's attachments array (wire:model="attachments")
    'attachments' => [],
    // True after "No, I still need help" on a resolved ticket
    'reopening' => false,
    // Where "Open a new request" goes once the ticket is closed
    'newRequestUrl',
])

@php
    use App\Helpdesk\Enums\TicketStatus;

    $textareaClass = 'w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3.5 py-2.5 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:border-white';
    $primaryClass = 'inline-flex items-center justify-center rounded-xl bg-amber-500 px-6 py-2.5 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-amber-600 disabled:opacity-60 transition';
@endphp

@if ($ticket->status === TicketStatus::Resolved && ! $reopening)
    {{-- Resolved: confirm or reopen. A plain reply is not offered, so a "thanks" does not reopen the ticket. --}}
    <div data-test="resolved-choice" class="space-y-4 rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5 dark:border-emerald-900 dark:bg-emerald-950/20">
        <div>
            <h2 class="text-sm font-bold text-zinc-900 dark:text-white">We marked this request as resolved. Is your issue solved?</h2>
            @if ($closesAt = $ticket->autoClosesAt())
                <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">If we do not hear from you, it closes on @datetime($closesAt).</p>
            @endif
        </div>
        <div class="flex flex-col gap-3 sm:flex-row">
            <button type="button" wire:click="closeTicket" class="{{ $primaryClass }} bg-emerald-600 hover:bg-emerald-700">
                Yes, it is solved
            </button>
            <button type="button" wire:click="$set('reopening', true)" class="inline-flex items-center justify-center rounded-xl border border-zinc-300 bg-white px-6 py-2.5 text-xs font-bold uppercase tracking-wider text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800">
                No, I still need help
            </button>
        </div>
    </div>
@elseif ($ticket->status->acceptsReplies())
    <form wire:submit="sendReply" class="space-y-3 rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <label for="reply" class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">
            {{ $reopening ? 'Tell us what is still wrong. This reopens your request.' : 'Reply' }}
        </label>
        <textarea id="reply" wire:model="reply" rows="4" maxlength="{{ config('helpdesk.body_max') }}" class="{{ $textareaClass }}" placeholder="Write your message"></textarea>
        @error('reply') <p class="text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror

        <x-support.attachments-input :files="$attachments" />

        <div class="flex flex-col-reverse gap-3 border-t border-zinc-100 pt-3 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
            @if ($reopening)
                <button type="button" wire:click="$set('reopening', false)" class="text-xs font-semibold text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white">
                    Cancel
                </button>
            @else
                <button type="button" wire:click="closeTicket" wire:confirm="Close this request? You will not be able to reply on it again."
                        class="text-xs font-semibold text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white">
                    My issue is sorted, close this request
                </button>
            @endif
            <button type="submit" wire:loading.attr="disabled" wire:target="sendReply,attachments" class="{{ $primaryClass }}">
                {{ $reopening ? 'Reopen request' : 'Send reply' }}
            </button>
        </div>
    </form>
@else
    <div data-test="ticket-closed" class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 text-center text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
        This request is closed.
        <a href="{{ $newRequestUrl }}" wire:navigate class="font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-400">Open a new request</a>
        if you still need help.
    </div>
@endif
