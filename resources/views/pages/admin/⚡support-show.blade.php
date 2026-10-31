<?php

namespace App\Livewire\Admin;

use App\Helpdesk\Contracts\SellerOrderLookupInterface;
use App\Helpdesk\DTOs\SellerOrderRef;
use App\Helpdesk\Enums\MessageAuthor;
use App\Helpdesk\Enums\RequesterType;
use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Enums\TicketPriority;
use App\Helpdesk\Enums\TicketStatus;
use App\Helpdesk\Models\SupportTicket;
use App\Helpdesk\Models\SupportTicketMessage;
use App\Helpdesk\Services\HelpdeskService;
use App\Models\Admin;
use App\Support\AdminPermissions;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Support Ticket')] class extends Component {
    use WithFileUploads;

    /** The case's ticket number: the customer ticket when a seller side exists. */
    public string $ticketNumber;

    /** Which side of a case is open: "customer" or "seller". */
    #[Url]
    public string $side = 'customer';

    public string $reply = '';

    public array $attachments = [];

    public bool $internal = false;

    /** Status after a public reply. */
    public string $statusAfter = 'pending';

    // "Ask the seller" form (customer tickets about an order)
    public bool $asking = false;

    public string $askSellerOrderId = '';

    public string $askCategory = 'seller_orders';

    public string $askSubject = '';

    public string $askBody = '';

    /** @var list<string> "messageId:index" of the customer's attachments to pass on */
    public array $askAttachments = [];

    // Forwarding one requester message to the linked ticket
    public ?int $forwardingMessageId = null;

    public string $forwardBody = '';

    /** @var list<int|string> attachment indexes of the forwarded message */
    public array $forwardAttachments = [];

    public string $forwardStatusAfter = 'pending';

    public function mount(string $ticket): void
    {
        $this->authorize('viewAny', SupportTicket::class);

        $found = SupportTicket::query()->with('linkedTicket')->where('ticket_number', $ticket)->first();
        abort_unless($found, 404);

        // A seller ticket inside a customer's case opens as that case, on the seller tab
        if ($found->isSellerSideOfCase() && $found->linkedTicket) {
            $this->ticketNumber = $found->linkedTicket->ticket_number;
            $this->side = 'seller';
            $this->redirectRoute('admin.support.show', ['ticket' => $found->linkedTicket->ticket_number, 'side' => 'seller'], navigate: true);

            return;
        }

        $this->ticketNumber = $ticket;
        if (! $found->linkedTicket) {
            $this->side = 'customer';
        }

        app(HelpdeskService::class)->markReadByStaff($found, $this->admin()->id);
    }

    /**
     * The case: the ticket in the URL (a customer ticket, or a standalone seller ticket).
     */
    #[Computed]
    public function caseTicket(): ?SupportTicket
    {
        return SupportTicket::query()->with(['assignee', 'user', 'sellerProfile', 'linkedTicket'])->where('ticket_number', $this->ticketNumber)->first();
    }

    /**
     * The ticket on the open tab; every action on this page works on it.
     */
    #[Computed]
    public function ticket(): ?SupportTicket
    {
        $case = $this->caseTicket;

        if ($this->side === 'seller' && $case?->linkedTicket) {
            return SupportTicket::query()->with(['assignee', 'user', 'sellerProfile', 'linkedTicket'])->find($case->linkedTicket->id);
        }

        return $case;
    }

    public function switchSide(string $side): void
    {
        $this->side = $side === 'seller' && $this->caseTicket->linkedTicket ? 'seller' : 'customer';
        $this->reset(['reply', 'attachments', 'internal', 'asking', 'forwardingMessageId', 'forwardBody', 'forwardAttachments']);
        $this->statusAfter = 'pending';
        $this->resetErrorBag();
        $this->refreshTicket();
    }

    /**
     * @return Collection<int, SupportTicketMessage>
     */
    #[Computed]
    public function messages(): Collection
    {
        return $this->ticket->messages()->with('admin')->get();
    }

    /**
     * @return Collection<int, Admin>
     */
    #[Computed]
    public function staff(): Collection
    {
        return Admin::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * The requester's other tickets, newest first.
     *
     * @return Collection<int, SupportTicket>
     */
    #[Computed]
    public function otherTickets(): Collection
    {
        if (! $this->ticket->user_id) {
            return collect();
        }

        return SupportTicket::query()
            ->where('user_id', $this->ticket->user_id)
            ->whereKeyNot($this->ticket->id)
            ->latest('last_message_at')
            ->limit(5)
            ->get();
    }

    /**
     * Sellers' packages in this customer ticket's order.
     *
     * @return list<SellerOrderRef>
     */
    #[Computed]
    public function sellerPackages(): array
    {
        if ($this->ticket->requester_type !== RequesterType::Customer || ! $this->ticket->order_id) {
            return [];
        }

        return app(SellerOrderLookupInterface::class)->forOrder($this->ticket->order_id);
    }

    /**
     * Attachments the customer sent, which staff may tick to pass on.
     *
     * @return list<array{ref: string, label: string}>
     */
    #[Computed]
    public function requesterAttachments(): array
    {
        $files = [];
        foreach ($this->messages as $message) {
            if ($message->author_type !== MessageAuthor::Requester || $message->is_internal) {
                continue;
            }
            foreach ($message->attachments ?? [] as $index => $file) {
                $files[] = [
                    'ref' => $message->id.':'.$index,
                    'label' => $file['name'].' ('.format_datetime($message->created_at).')',
                ];
            }
        }

        return $files;
    }

    #[Computed]
    public function forwardingMessage(): ?SupportTicketMessage
    {
        return $this->forwardingMessageId ? $this->messages->firstWhere('id', $this->forwardingMessageId) : null;
    }

    public function canForward(): bool
    {
        $linked = $this->ticket->linkedTicket;

        return $linked !== null && $linked->status->acceptsReplies() && auth('admin')->user()?->can('reply', SupportTicket::class);
    }

    protected function admin(): Admin
    {
        /** @var Admin $admin */
        $admin = auth('admin')->user();

        return $admin;
    }

    protected function refreshTicket(): void
    {
        unset($this->caseTicket, $this->ticket, $this->messages, $this->otherTickets, $this->requesterAttachments, $this->forwardingMessage, $this->sellerPackages);

        // Whatever arrived since the page opened is now in front of this staff member
        if ($this->caseTicket) {
            app(HelpdeskService::class)->markReadByStaff($this->caseTicket, $this->admin()->id);
        }
    }

    public function removeAttachment(int $index): void
    {
        unset($this->attachments[$index]);
        $this->attachments = array_values($this->attachments);
    }

    public function sendReply(HelpdeskService $helpdesk): void
    {
        $this->authorize('reply', SupportTicket::class);
        $this->resetErrorBag();

        $statusAfter = TicketStatus::tryFrom($this->statusAfter) ?? TicketStatus::Pending;

        try {
            $helpdesk->replyAsStaff($this->ticket, $this->admin(), $this->reply, $this->attachments, $this->internal, $statusAfter);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError(str_starts_with($field, 'attachments') ? 'attachments' : 'reply', $messages[0]);
            }

            return;
        } catch (\DomainException $e) {
            $this->addError('reply', $e->getMessage());

            return;
        }

        session()->flash('success', $this->internal ? 'Internal note added.' : 'Reply sent to the '.$this->ticket->requester_type->value.'.');
        $this->reset(['reply', 'attachments', 'internal']);
        $this->statusAfter = 'pending';
        $this->refreshTicket();
    }

    public function startAsking(): void
    {
        $this->authorize('reply', SupportTicket::class);

        $this->asking = true;
        $this->askSellerOrderId = count($this->sellerPackages) === 1 ? $this->sellerPackages[0]->sellerOrderId : '';
        $this->askSubject = 'Order '.$this->ticket->order_number.': '.$this->ticket->subject;
        $this->askBody = '';
        $this->askAttachments = [];
        $this->resetErrorBag();
    }

    public function askSeller(HelpdeskService $helpdesk): void
    {
        $this->authorize('reply', SupportTicket::class);
        $this->resetErrorBag();

        try {
            $sellerTicket = $helpdesk->askSeller(
                customerTicket: $this->ticket,
                admin: $this->admin(),
                sellerOrderId: $this->askSellerOrderId,
                category: TicketCategory::tryFrom($this->askCategory) ?? TicketCategory::SellerOrders,
                subject: $this->askSubject,
                body: $this->askBody,
                attachmentRefs: array_values(array_map('strval', $this->askAttachments)),
            );
        } catch (ValidationException $e) {
            $this->addError('ask', collect($e->errors())->flatten()->first());

            return;
        } catch (\DomainException $e) {
            $this->addError('ask', $e->getMessage());

            return;
        }

        session()->flash('success', "Seller ticket {$sellerTicket->ticket_number} opened. This ticket now waits on the seller.");
        $this->reset(['asking', 'askSellerOrderId', 'askSubject', 'askBody', 'askAttachments']);
        $this->refreshTicket();
    }

    public function startForward(int $messageId): void
    {
        $this->authorize('reply', SupportTicket::class);

        $message = $this->messages->firstWhere('id', $messageId);
        abort_unless($message && $message->author_type === MessageAuthor::Requester && ! $message->is_internal, 404);

        $this->forwardingMessageId = $messageId;
        $this->forwardBody = $message->body;
        $this->forwardAttachments = [];
        $this->forwardStatusAfter = 'pending';
        $this->resetErrorBag();
        unset($this->forwardingMessage);
    }

    public function cancelForward(): void
    {
        $this->reset(['forwardingMessageId', 'forwardBody', 'forwardAttachments']);
    }

    public function forward(HelpdeskService $helpdesk): void
    {
        $this->authorize('reply', SupportTicket::class);
        $this->resetErrorBag();

        $message = $this->forwardingMessage;
        if (! $message) {
            return;
        }

        try {
            $helpdesk->forward(
                message: $message->setRelation('ticket', $this->ticket),
                admin: $this->admin(),
                body: $this->forwardBody,
                attachmentIndexes: array_values(array_map('intval', $this->forwardAttachments)),
                statusAfter: TicketStatus::tryFrom($this->forwardStatusAfter),
            );
        } catch (ValidationException $e) {
            $this->addError('forward', collect($e->errors())->flatten()->first());

            return;
        } catch (\DomainException $e) {
            $this->addError('forward', $e->getMessage());

            return;
        }

        session()->flash('success', 'Forwarded to '.$this->ticket->linkedTicket->ticket_number.'.');
        $this->cancelForward();
        $this->refreshTicket();
    }

    public function updateStatus(string $status, HelpdeskService $helpdesk): void
    {
        $this->authorize('reply', SupportTicket::class);

        try {
            $helpdesk->setStatus($this->ticket, $this->admin(), TicketStatus::from($status));
            session()->flash('success', 'Status updated.');
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
        $this->refreshTicket();
    }

    public function updatePriority(string $priority, HelpdeskService $helpdesk): void
    {
        $this->authorize('reply', SupportTicket::class);

        $helpdesk->setPriority($this->ticket, $this->admin(), TicketPriority::from($priority));
        session()->flash('success', 'Priority updated.');
        $this->refreshTicket();
    }

    public function assignTo(string $adminId, HelpdeskService $helpdesk): void
    {
        $this->authorize('assign', SupportTicket::class);

        try {
            $helpdesk->assign($this->ticket, $this->admin(), $adminId === '' ? null : (int) $adminId);
            session()->flash('success', 'Assignment updated.');
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
        $this->refreshTicket();
    }

    /**
     * Staff who can reply may take an unassigned ticket without the assign permission.
     */
    public function takeTicket(HelpdeskService $helpdesk): void
    {
        $this->authorize('reply', SupportTicket::class);

        if ($this->ticket->assigned_admin_id !== null) {
            session()->flash('error', 'Someone else has already taken this ticket.');
            $this->refreshTicket();

            return;
        }

        $helpdesk->assign($this->ticket, $this->admin(), $this->admin()->id);
        session()->flash('success', 'Ticket assigned to you.');
        $this->refreshTicket();
    }
}; ?>

@php
    $case = $this->caseTicket;
    $ticket = $this->ticket;
    $linked = $ticket->linkedTicket;
    $isCustomer = $ticket->requester_type === RequesterType::Customer;
    $sellerSide = $case->requester_type === RequesterType::Customer ? $case->linkedTicket : null;
    $inputClass = 'w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3.5 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:border-white';
@endphp

<div class="space-y-6">
    {{-- Case header: the customer's complaint, whichever side is open --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <a href="{{ route('admin.support.index') }}" wire:navigate class="text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white">&larr; Support inbox</a>
            <h1 class="mt-1 break-words text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ $case->subject }}</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ $case->ticket_number }} &middot; {{ $case->category->label() }}
                @if ($case->order_number) &middot; {{ $case->order_number }} @endif
                &middot; opened @datetime($case->created_at)
                @if ($case->assignee) &middot; {{ $case->assignee->name }} @endif
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2 self-start">
            @if ($case->attention === 'seller_replied')
                <span class="inline-flex items-center rounded-full bg-violet-600 px-2.5 py-0.5 text-xs font-semibold text-white">Seller replied</span>
            @endif
            @if ($case->isSellerOverdue() || $sellerSide?->isSellerOverdue())
                <span class="inline-flex items-center rounded-full bg-rose-600 px-2.5 py-0.5 text-xs font-semibold text-white">Seller overdue</span>
            @endif
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $case->status->badgeClasses() }}">{{ $case->status->label() }}</span>
        </div>
    </div>

    @if ($sellerSide)
        {{-- Each side keeps its own conversation; staff switch between them here --}}
        <div data-test="case-tabs" class="flex gap-1 border-b border-zinc-200 dark:border-zinc-800">
            @foreach (['customer' => $case, 'seller' => $sellerSide] as $tab => $tabTicket)
                <button type="button" wire:click="switchSide('{{ $tab }}')" @class([
                    '-mb-px flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-semibold transition',
                    'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' => $side === $tab,
                    'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white' => $side !== $tab,
                ])>
                    <span>{{ $tab === 'customer' ? 'Customer' : 'Seller' }}</span>
                    <span class="text-xs font-normal text-zinc-400">{{ $tabTicket->requester_name }}</span>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 {{ $tabTicket->status->badgeClasses() }}">{{ $tabTicket->status->label() }}</span>
                    @if ($tab === 'seller' && $tabTicket->isSellerOverdue())
                        <span class="size-2 rounded-full bg-rose-500" title="Seller overdue"></span>
                    @endif
                </button>
            @endforeach
        </div>
    @endif

    @if (session()->has('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">{{ session('success') }}</div>
    @endif
    @if (session()->has('error'))
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300">{{ session('error') }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <!-- Thread & reply -->
        <div class="space-y-6 lg:col-span-2">
            <x-support.thread :ticket="$ticket" :messages="$this->messages" viewer="staff" :forwardable="$this->canForward()" />

            @if ($this->forwardingMessage)
                <form wire:submit="forward" class="space-y-3 rounded-2xl border border-violet-300 bg-violet-50/60 p-5 shadow-xs dark:border-violet-800 dark:bg-violet-950/20">
                    <div>
                        <h2 class="text-sm font-bold text-zinc-900 dark:text-white">Forward to the {{ $linked->requester_type->value }} ({{ $linked->ticket_number }})</h2>
                        <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">
                            It goes as a message from {{ brand_name() }} Support. Edit it first: names, store names, emails, phone numbers, UPI IDs and ticket numbers are blocked.
                        </p>
                    </div>
                    <textarea wire:model="forwardBody" rows="5" class="{{ $inputClass }}"></textarea>

                    @if ($this->forwardingMessage->attachments)
                        <fieldset class="space-y-1">
                            <legend class="mb-1 text-xs font-semibold text-zinc-700 dark:text-zinc-300">Attachments to include (images are cleaned of location and device data, files are renamed)</legend>
                            @foreach ($this->forwardingMessage->attachments as $index => $file)
                                <label class="flex items-center gap-2 text-xs text-zinc-700 dark:text-zinc-300">
                                    <input type="checkbox" wire:model="forwardAttachments" value="{{ $index }}" class="rounded border-zinc-300 dark:border-zinc-600">
                                    <span>{{ $file['name'] }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif

                    @error('forward') <p class="text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror

                    <div class="flex flex-col gap-3 border-t border-violet-200 pt-3 sm:flex-row sm:items-center sm:justify-end dark:border-violet-900">
                        @if ($linked->requester_type === RequesterType::Customer)
                            <label class="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                                <span>Customer ticket then</span>
                                <select wire:model="forwardStatusAfter" class="rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1.5 text-xs text-zinc-800 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                    <option value="pending">Awaiting customer</option>
                                    <option value="resolved">Resolved</option>
                                    <option value="open">Open</option>
                                </select>
                            </label>
                        @endif
                        <button type="button" wire:click="cancelForward" class="text-xs font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Cancel</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="forward" class="inline-flex items-center justify-center rounded-xl bg-violet-700 px-5 py-2 text-sm font-semibold text-white shadow-xs hover:bg-violet-800 disabled:opacity-60">
                            Forward to {{ $linked->requester_type->value }}
                        </button>
                    </div>
                </form>
            @endif

            @if ($asking)
                <form wire:submit="askSeller" class="space-y-3 rounded-2xl border border-violet-300 bg-violet-50/60 p-5 shadow-xs dark:border-violet-800 dark:bg-violet-950/20">
                    <div>
                        <h2 class="text-sm font-bold text-zinc-900 dark:text-white">Ask the seller</h2>
                        <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">
                            Opens a seller ticket from {{ brand_name() }} Support. The seller never sees the customer's name, email, phone or this ticket. The seller has {{ config('helpdesk.seller_reply_hours') }} hours to reply.
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Seller</label>
                            <select wire:model="askSellerOrderId" class="{{ $inputClass }}">
                                <option value="">Choose the seller's package</option>
                                @foreach ($this->sellerPackages as $package)
                                    <option value="{{ $package->sellerOrderId }}">{{ \Illuminate\Support\Str::limit($package->summary, 50) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Topic</label>
                            <select wire:model="askCategory" class="{{ $inputClass }}">
                                @foreach (TicketCategory::forSellers() as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Subject</label>
                        <input type="text" wire:model="askSubject" maxlength="{{ config('helpdesk.subject_max') }}" class="{{ $inputClass }}" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Message to the seller</label>
                        <textarea wire:model="askBody" rows="5" placeholder="e.g. The customer reports receiving size L instead of M. Do you have size M in stock to replace it?" class="{{ $inputClass }}"></textarea>
                    </div>

                    @if ($this->requesterAttachments)
                        <fieldset class="space-y-1">
                            <legend class="mb-1 text-xs font-semibold text-zinc-700 dark:text-zinc-300">Customer attachments to pass on (check each one: a parcel label shows the customer's name and address)</legend>
                            @foreach ($this->requesterAttachments as $file)
                                <label class="flex items-center gap-2 text-xs text-zinc-700 dark:text-zinc-300">
                                    <input type="checkbox" wire:model="askAttachments" value="{{ $file['ref'] }}" class="rounded border-zinc-300 dark:border-zinc-600">
                                    <span>{{ $file['label'] }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif

                    @error('ask') <p class="text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror

                    <div class="flex items-center justify-end gap-3 border-t border-violet-200 pt-3 dark:border-violet-900">
                        <button type="button" wire:click="$set('asking', false)" class="text-xs font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Cancel</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="askSeller" class="inline-flex items-center justify-center rounded-xl bg-violet-700 px-5 py-2 text-sm font-semibold text-white shadow-xs hover:bg-violet-800 disabled:opacity-60">
                            Send to seller
                        </button>
                    </div>
                </form>
            @endif

            @can('reply', \App\Helpdesk\Models\SupportTicket::class)
                <form wire:submit="sendReply" @class([
                    'space-y-3 rounded-2xl border p-5 shadow-xs',
                    'border-amber-300 bg-amber-50/60 dark:border-amber-700 dark:bg-amber-950/20' => $internal,
                    'border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900' => ! $internal,
                ])>
                    <div class="flex items-center gap-1 rounded-lg bg-zinc-100 p-1 text-xs font-semibold dark:bg-zinc-800 w-fit">
                        <button type="button" wire:click="$set('internal', false)" @class(['rounded-md px-3 py-1.5 transition', 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white' => ! $internal, 'text-zinc-500 dark:text-zinc-400' => $internal])>Reply to {{ $ticket->requester_type->value }}</button>
                        <button type="button" wire:click="$set('internal', true)" @class(['rounded-md px-3 py-1.5 transition', 'bg-white text-amber-800 shadow-xs dark:bg-zinc-700 dark:text-amber-300' => $internal, 'text-zinc-500 dark:text-zinc-400' => ! $internal])>Internal note</button>
                    </div>

                    <textarea wire:model="reply" rows="5" maxlength="{{ config('helpdesk.body_max') }}"
                              placeholder="{{ $internal ? 'Only staff can see this note.' : 'Write your reply to the '.$ticket->requester_type->value.'.' }}"
                              class="{{ $inputClass }} py-2.5"></textarea>
                    @error('reply') <p class="text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror

                    <x-support.attachments-input :files="$attachments" />

                    <div class="flex flex-col gap-3 border-t border-zinc-100 pt-3 sm:flex-row sm:items-center sm:justify-end dark:border-zinc-800">
                        @unless ($internal)
                            <label class="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                                <span>Then set status to</span>
                                <select wire:model="statusAfter" class="rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1.5 text-xs text-zinc-800 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                    <option value="pending">Awaiting {{ $ticket->requester_type->value }}</option>
                                    <option value="resolved">Resolved</option>
                                    <option value="open">Open</option>
                                </select>
                            </label>
                        @endunless
                        <button type="submit" wire:loading.attr="disabled" wire:target="sendReply,attachments"
                                class="inline-flex items-center justify-center rounded-xl bg-zinc-900 px-5 py-2 text-sm font-semibold text-white shadow-xs hover:bg-zinc-800 disabled:opacity-60 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">
                            {{ $internal ? 'Add note' : 'Send reply' }}
                        </button>
                    </div>
                </form>
            @endcan
        </div>

        <!-- Side panel -->
        <aside class="space-y-4">
            <div class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-400">Ticket</h2>

                <div>
                    <label class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400">Status</label>
                    @can('reply', \App\Helpdesk\Models\SupportTicket::class)
                        <select wire:change="updateStatus($event.target.value)" class="w-full rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-800 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                            @foreach (TicketStatus::cases() as $option)
                                @continue($option === TicketStatus::AwaitingSeller && (! $isCustomer || ! $linked))
                                @continue($option === TicketStatus::Answered && $isCustomer)
                                <option value="{{ $option->value }}" @selected($ticket->status === $option)>{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    @else
                        <p class="text-sm text-zinc-900 dark:text-white">{{ $ticket->status->label() }}</p>
                    @endcan
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400">Priority</label>
                    @can('reply', \App\Helpdesk\Models\SupportTicket::class)
                        <select wire:change="updatePriority($event.target.value)" class="w-full rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-800 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                            @foreach (TicketPriority::cases() as $option)
                                <option value="{{ $option->value }}" @selected($ticket->priority === $option)>{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    @else
                        <p class="text-sm {{ $ticket->priority->badgeClasses() }}">{{ $ticket->priority->label() }}</p>
                    @endcan
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400">Assignee</label>
                    @can('assign', \App\Helpdesk\Models\SupportTicket::class)
                        <select wire:change="assignTo($event.target.value)" class="w-full rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-800 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                            <option value="">Unassigned</option>
                            @foreach ($this->staff as $member)
                                <option value="{{ $member->id }}" @selected((int) $ticket->assigned_admin_id === $member->id)>{{ $member->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <p class="text-sm text-zinc-900 dark:text-white">{{ $ticket->assignee?->name ?? 'Unassigned' }}</p>
                        @if (! $ticket->assigned_admin_id)
                            @can('reply', \App\Helpdesk\Models\SupportTicket::class)
                                <button type="button" wire:click="takeTicket" class="mt-1 text-xs font-semibold text-sky-700 hover:text-sky-800 dark:text-sky-400">Take this ticket</button>
                            @endcan
                        @endif
                    @endcan
                </div>

                <dl class="space-y-2 border-t border-zinc-100 pt-4 text-xs dark:border-zinc-800">
                    <div class="flex justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">First response</dt><dd class="text-right text-zinc-900 dark:text-white">{{ $ticket->first_response_at ? format_datetime($ticket->first_response_at) : '—' }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">Last activity</dt><dd class="text-right text-zinc-900 dark:text-white">@datetime($ticket->last_message_at)</dd></div>
                    @if ($ticket->reply_due_at)
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">Seller reply due</dt><dd @class(['text-right', 'font-semibold text-rose-600 dark:text-rose-400' => $ticket->isSellerOverdue(), 'text-zinc-900 dark:text-white' => ! $ticket->isSellerOverdue()])>@datetime($ticket->reply_due_at)</dd></div>
                    @endif
                    @if ($ticket->resolved_at)
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">Resolved</dt><dd class="text-right text-zinc-900 dark:text-white">@datetime($ticket->resolved_at)</dd></div>
                    @endif
                    @if ($closesAt = $ticket->autoClosesAt())
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">{{ ucfirst($ticket->requester_type->value) }} can reopen until</dt><dd class="text-right text-zinc-900 dark:text-white">@datetime($closesAt)</dd></div>
                    @endif
                    @if ($ticket->closed_at)
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">Closed</dt><dd class="text-right text-zinc-900 dark:text-white">@datetime($ticket->closed_at)</dd></div>
                    @endif
                </dl>
            </div>

            <!-- Linked ticket -->
            @if ($linked || ($isCustomer && $ticket->order_id))
                <div data-test="linked-ticket-panel" class="space-y-3 rounded-2xl border border-violet-200 bg-white p-5 text-sm shadow-xs dark:border-violet-900 dark:bg-zinc-900">
                    <h2 class="text-xs font-bold uppercase tracking-wider text-violet-500">{{ $isCustomer ? 'Seller side' : 'Customer side' }}</h2>
                    @if ($linked)
                        <button type="button" wire:click="switchSide('{{ $isCustomer ? 'seller' : 'customer' }}')" class="block text-left hover:underline">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $linked->ticket_number }}</span>
                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $linked->requester_name }} &middot; {{ $linked->status->label() }}</span>
                        </button>
                        @if ($linked->reply_due_at)
                            <p @class(['text-xs', 'font-semibold text-rose-600 dark:text-rose-400' => $linked->isSellerOverdue(), 'text-zinc-500 dark:text-zinc-400' => ! $linked->isSellerOverdue()])>
                                Seller reply due @datetime($linked->reply_due_at)
                            </p>
                        @endif
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Use "Forward" on a message to pass it across.</p>
                    @endif
                    @if ($isCustomer && $ticket->status->acceptsReplies() && (! $linked || $linked->status === TicketStatus::Closed) && ! $asking)
                        @can('reply', \App\Helpdesk\Models\SupportTicket::class)
                            <button type="button" wire:click="startAsking" class="inline-flex items-center rounded-lg bg-violet-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-800">Ask the seller</button>
                        @endcan
                    @endif
                </div>
            @endif

            <div class="space-y-3 rounded-2xl border border-zinc-200 bg-white p-5 text-sm shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-400">{{ $ticket->requester_type->label() }}</h2>
                <div>
                    <p class="font-medium text-zinc-900 dark:text-white">{{ $ticket->requester_name }}</p>
                    <p class="break-all text-xs text-zinc-500 dark:text-zinc-400">{{ $ticket->requester_email }}</p>
                    @if ($ticket->requester_type === RequesterType::Seller)
                        @if ($ticket->sellerProfile)
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Store status: {{ $ticket->sellerProfile->status->label() }}</p>
                            @can(AdminPermissions::SELLERS_VIEW)
                                <a href="{{ route('admin.sellers.show', ['sellerProfile' => $ticket->seller_profile_id]) }}" wire:navigate class="mt-1 inline-block text-xs font-semibold text-sky-700 hover:text-sky-800 dark:text-sky-400">View seller &rarr;</a>
                            @endcan
                        @else
                            <p class="mt-1 text-xs italic text-zinc-400">Seller profile deleted</p>
                        @endif
                    @elseif ($ticket->user_id)
                        @can(AdminPermissions::CUSTOMERS_VIEW)
                            <a href="{{ route('admin.customers.show', ['user' => $ticket->user_id]) }}" wire:navigate class="mt-1 inline-block text-xs font-semibold text-sky-700 hover:text-sky-800 dark:text-sky-400">View customer &rarr;</a>
                        @endcan
                    @else
                        <p class="mt-1 text-xs italic text-zinc-400">Account deleted</p>
                    @endif
                </div>

                @if ($ticket->order_number)
                    <div class="border-t border-zinc-100 pt-3 dark:border-zinc-800">
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Order</p>
                        @can(AdminPermissions::ORDERS_VIEW)
                            <a href="{{ route('admin.orders.show', ['order' => $ticket->order_id]) }}" wire:navigate class="font-medium text-sky-700 hover:text-sky-800 dark:text-sky-400">{{ $ticket->order_number }} &rarr;</a>
                        @else
                            <p class="font-medium text-zinc-900 dark:text-white">{{ $ticket->order_number }}</p>
                        @endcan
                    </div>
                @endif

                @if ($this->otherTickets->isNotEmpty())
                    <div class="border-t border-zinc-100 pt-3 dark:border-zinc-800">
                        <p class="mb-1.5 text-xs text-zinc-500 dark:text-zinc-400">Other requests</p>
                        <ul class="space-y-1.5">
                            @foreach ($this->otherTickets as $other)
                                <li>
                                    <a href="{{ route('admin.support.show', ['ticket' => $other->ticket_number]) }}" wire:navigate class="block text-xs hover:underline">
                                        <span class="line-clamp-1 font-medium text-zinc-800 dark:text-zinc-200">{{ $other->subject }}</span>
                                        <span class="text-zinc-400">{{ $other->ticket_number }} &middot; {{ $other->status->label() }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </aside>
    </div>
</div>
