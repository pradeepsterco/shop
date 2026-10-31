<?php

namespace App\Livewire\Seller;

use App\Helpdesk\Contracts\SellerOrderLookupInterface;
use App\Helpdesk\DTOs\SellerOrderRef;
use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Services\HelpdeskService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.seller')] #[Title('New Support Request')] class extends Component {
    use WithFileUploads;

    /** Seller order (package) id. */
    #[Url(as: 'order')]
    public string $sellerOrderId = '';

    public string $category = '';

    public string $subject = '';

    public string $body = '';

    public array $attachments = [];

    public function mount(): void
    {
        if ($this->sellerOrderId !== '' && $this->category === '') {
            $this->category = TicketCategory::SellerOrders->value;
        }
    }

    /**
     * @return list<SellerOrderRef>
     */
    #[Computed]
    public function orders(): array
    {
        $profileId = auth()->user()->sellerProfile?->id;

        return $profileId ? app(SellerOrderLookupInterface::class)->recentForSeller((int) $profileId) : [];
    }

    public function removeAttachment(int $index): void
    {
        unset($this->attachments[$index]);
        $this->attachments = array_values($this->attachments);
    }

    public function submit(HelpdeskService $helpdesk): void
    {
        $this->resetErrorBag();

        $category = TicketCategory::tryFrom($this->category);
        if (! $category) {
            $this->addError('category', 'Choose what your request is about.');

            return;
        }

        try {
            $ticket = $helpdesk->openSellerTicket(
                user: auth()->user(),
                category: $category,
                subject: $this->subject,
                body: $this->body,
                sellerOrderId: $this->sellerOrderId ?: null,
                attachments: $this->attachments,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError(str_starts_with($field, 'attachments') ? 'attachments' : $field, $messages[0]);
            }

            return;
        } catch (\DomainException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('support_success', 'Your request has been sent. We will reply here and by email.');
        $this->redirectRoute('seller.support.show', ['ticket' => $ticket->ticket_number], navigate: true);
    }
}; ?>

<div class="max-w-3xl space-y-6">
    <div>
        <a href="{{ route('seller.support.index') }}" wire:navigate class="text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white">&larr; Seller Support</a>
        <h1 class="mt-1 text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl dark:text-white">New support request</h1>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Choosing the order and attaching a screenshot or document helps us sort it out faster.</p>
    </div>

    <form wire:submit="submit" class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs sm:p-6 dark:border-zinc-800 dark:bg-zinc-900">
        @error('form')
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300">{{ $message }}</div>
        @enderror

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="category" class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">What is it about?</label>
                <select id="category" wire:model="category"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2.5 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:border-white">
                    <option value="">Choose a topic</option>
                    @foreach (TicketCategory::forSellers() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                @error('category') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="order" class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Order <span class="font-normal text-zinc-400">(optional)</span></label>
                <select id="order" wire:model="sellerOrderId"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2.5 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:border-white">
                    <option value="">Not about an order</option>
                    @foreach ($this->orders as $order)
                        <option value="{{ $order->sellerOrderId }}">{{ $order->orderNumber }} &middot; {{ \Illuminate\Support\Str::limit($order->summary, 40) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label for="subject" class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Subject</label>
            <input id="subject" type="text" wire:model="subject" maxlength="{{ config('helpdesk.subject_max') }}" placeholder="e.g. Payout for last week not received"
                   class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3.5 py-2.5 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500 dark:focus:border-white" />
            @error('subject') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="body" class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Message</label>
            <textarea id="body" wire:model="body" rows="6" maxlength="{{ config('helpdesk.body_max') }}" placeholder="Describe the problem and what you would like us to do."
                      class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3.5 py-2.5 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500 dark:focus:border-white"></textarea>
            @error('body') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
        </div>

        <x-support.attachments-input :files="$attachments" label="Attachments" />

        <div class="flex items-center justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
            <a href="{{ route('seller.support.index') }}" wire:navigate class="text-xs font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Cancel</a>
            <button type="submit" wire:loading.attr="disabled" wire:target="submit,attachments"
                    class="inline-flex items-center rounded-xl bg-amber-500 px-6 py-2.5 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-amber-600 disabled:opacity-60 transition">
                <span wire:loading.remove wire:target="submit">Send request</span>
                <span wire:loading wire:target="submit">Sending…</span>
            </button>
        </div>
    </form>
</div>
