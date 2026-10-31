<?php

namespace App\Livewire\Storefront;

use App\Helpdesk\Enums\RequesterType;
use App\Helpdesk\Enums\TicketStatus;
use App\Helpdesk\Models\SupportTicket;
use App\Helpdesk\Services\HelpdeskService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Support Request')] class extends Component {
    use WithFileUploads;

    public string $ticketNumber;

    public string $reply = '';

    public array $attachments = [];

    /** "No, I still need help" was chosen on a resolved ticket. */
    public bool $reopening = false;

    public function mount(string $ticket): void
    {
        $this->ticketNumber = $ticket;

        // 404 rather than 403 so ticket numbers cannot be probed
        abort_unless($this->ticket && auth()->user()->can('view', $this->ticket), 404);

        app(HelpdeskService::class)->markReadByRequester($this->ticket);
    }

    #[Computed]
    public function ticket(): ?SupportTicket
    {
        // Seller tickets live in the seller panel
        return SupportTicket::query()
            ->where('ticket_number', $this->ticketNumber)
            ->where('requester_type', RequesterType::Customer->value)
            ->first();
    }

    /**
     * @return Collection<int, \App\Helpdesk\Models\SupportTicketMessage>
     */
    #[Computed]
    public function messages(): Collection
    {
        return $this->ticket->messages()->where('is_internal', false)->get();
    }

    public function removeAttachment(int $index): void
    {
        unset($this->attachments[$index]);
        $this->attachments = array_values($this->attachments);
    }

    public function sendReply(HelpdeskService $helpdesk): void
    {
        $this->authorize('view', $this->ticket);
        $this->resetErrorBag();

        try {
            $helpdesk->replyAsRequester($this->ticket, auth()->user(), $this->reply, $this->attachments, reopen: $this->reopening);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError(str_starts_with($field, 'attachments') ? 'attachments' : 'reply', $messages[0]);
            }

            return;
        } catch (\DomainException $e) {
            $this->addError('reply', $e->getMessage());

            return;
        }

        $this->reset(['reply', 'attachments', 'reopening']);
        unset($this->ticket, $this->messages);
    }

    public function closeTicket(HelpdeskService $helpdesk): void
    {
        $this->authorize('view', $this->ticket);

        $helpdesk->closeByRequester($this->ticket, auth()->user());
        unset($this->ticket, $this->messages);
    }
}; ?>

<div class="w-full max-w-3xl space-y-6">
    <nav class="mb-6 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('products.index') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>Home</a>
        <span>/</span>
        <a href="{{ route('support.index') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>Help &amp; Support</a>
        <span>/</span>
        <span class="truncate font-medium text-zinc-800 dark:text-zinc-200">{{ $this->ticket->ticket_number }}</span>
    </nav>

    @if (session()->has('support_success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
            {{ session('support_success') }}
        </div>
    @endif

    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs sm:p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-bold text-zinc-400">{{ $this->ticket->ticket_number }} &middot; {{ $this->ticket->category->label() }}</p>
                <h1 class="mt-1 break-words text-xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ $this->ticket->subject }}</h1>
                @if ($this->ticket->order_number)
                    <a href="{{ route('orders.show', ['order' => $this->ticket->order_number]) }}" wire:navigate class="mt-1 inline-block text-xs font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-400">
                        Order {{ $this->ticket->order_number }} &rarr;
                    </a>
                @endif
            </div>
            <span class="inline-flex shrink-0 items-center self-start rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $this->ticket->status->badgeClasses() }}">
                {{ $this->ticket->status->requesterLabel() }}
            </span>
        </div>
    </div>

    <x-support.thread :ticket="$this->ticket" :messages="$this->messages" viewer="requester" />

    <x-support.requester-actions
        :ticket="$this->ticket"
        :attachments="$attachments"
        :reopening="$reopening"
        :new-request-url="route('support.create', array_filter(['order' => $this->ticket->order_number]))" />
</div>
