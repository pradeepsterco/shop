<?php

namespace App\Livewire\Storefront;

use App\Helpdesk\Contracts\CustomerOrderLookupInterface;
use App\Helpdesk\DTOs\CustomerOrderRef;
use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Services\HelpdeskService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('New Support Request')] class extends Component {
    use WithFileUploads;

    #[Url(as: 'order')]
    public string $orderNumber = '';

    public string $category = '';

    public string $subject = '';

    public string $body = '';

    public array $attachments = [];

    public function mount(): void
    {
        if ($this->orderNumber !== '' && $this->category === '') {
            $this->category = TicketCategory::OrderIssue->value;
        }
    }

    /**
     * @return list<CustomerOrderRef>
     */
    #[Computed]
    public function orders(): array
    {
        return app(CustomerOrderLookupInterface::class)->recentForCustomer((int) auth()->id());
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
            $ticket = $helpdesk->openCustomerTicket(
                user: auth()->user(),
                category: $category,
                subject: $this->subject,
                body: $this->body,
                orderNumber: $this->orderNumber ?: null,
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
        $this->redirectRoute('support.show', ['ticket' => $ticket->ticket_number], navigate: true);
    }
}; ?>

<div class="w-full max-w-3xl space-y-6">
    <nav class="mb-6 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('products.index') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>Home</a>
        <span>/</span>
        <a href="{{ route('support.index') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>Help &amp; Support</a>
        <span>/</span>
        <span class="truncate font-medium text-zinc-800 dark:text-zinc-200">New request</span>
    </nav>

    <div>
        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-3xl">New support request</h1>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Tell us what happened. Adding the order and a photo helps us sort it out faster.</p>
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
                    @foreach (\App\Helpdesk\Enums\TicketCategory::forCustomers() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                @error('category') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="order" class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Order <span class="font-normal text-zinc-400">(optional)</span></label>
                <select id="order" wire:model="orderNumber"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2.5 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:border-white">
                    <option value="">Not about an order</option>
                    @foreach ($this->orders as $order)
                        <option value="{{ $order->orderNumber }}">{{ $order->orderNumber }} &middot; {{ \Illuminate\Support\Str::limit($order->summary, 40) }}</option>
                    @endforeach
                </select>
                @error('orderNumber') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label for="subject" class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Subject</label>
            <input id="subject" type="text" wire:model="subject" maxlength="{{ config('helpdesk.subject_max') }}" placeholder="e.g. Received the wrong size"
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
            <a href="{{ route('support.index') }}" wire:navigate class="text-xs font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Cancel</a>
            <button type="submit" wire:loading.attr="disabled" wire:target="submit,attachments"
                    class="inline-flex items-center rounded-xl bg-amber-500 px-6 py-2.5 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-amber-600 disabled:opacity-60 transition">
                <span wire:loading.remove wire:target="submit">Send request</span>
                <span wire:loading wire:target="submit">Sending…</span>
            </button>
        </div>
    </form>
</div>
