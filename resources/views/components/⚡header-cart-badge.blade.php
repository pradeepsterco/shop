<?php

use App\Services\CartService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    #[Computed]
    #[On('cart-updated')]
    public function count(): int
    {
        return app(CartService::class)->count();
    }
}; ?>

<a href="{{ route('cart') }}" class="relative inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm font-medium text-zinc-800 shadow-xs hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 transition" wire:navigate aria-label="Cart ({{ $this->count }})">
    <svg class="h-5 w-5 text-zinc-700 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" />
    </svg>
    <span class="hidden sm:inline">Cart</span>
    @if ($this->count > 0)
        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-amber-500 px-1.5 text-xs font-bold text-white">
            {{ $this->count > 99 ? '99+' : $this->count }}
        </span>
    @endif
</a>

