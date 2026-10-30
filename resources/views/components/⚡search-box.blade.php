<?php

use App\Search\Contracts\SearchSuggestionsInterface;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $query = '';

    /** Suggestions load only once the customer types, not for a query prefilled from the URL. */
    public bool $typed = false;

    public function mount(): void
    {
        $q = request()->query('q');
        $this->query = request()->routeIs('products.index') && is_string($q) ? $q : '';
    }

    public function updatedQuery(): void
    {
        $this->typed = true;
    }

    /**
     * @return array{query: string, scopes: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>, products: array<int, array<string, mixed>>}
     */
    #[Computed]
    public function suggestions(): array
    {
        if (! $this->typed) {
            return ['query' => '', 'scopes' => [], 'categories' => [], 'products' => []];
        }

        return app(SearchSuggestionsInterface::class)->suggest($this->query);
    }
}; ?>

<div
    x-data="{
        open: false,
        mobileOpen: false,
        active: -1,
        items() {
            return Array.from(this.$refs.panel.querySelectorAll('[data-suggestion]'));
        },
        move(step) {
            const items = this.items();
            if (! items.length) return;
            this.open = true;
            this.active = (this.active + step + items.length) % items.length;
        },
        choose(event) {
            const item = this.items()[this.active];
            if (this.open && item) {
                event.preventDefault();
                item.click();
            }
        },
        close() {
            this.open = false;
            this.mobileOpen = false;
            this.active = -1;
        },
    }"
    x-on:click.outside="open = false; active = -1"
    x-on:keydown.escape="close()"
    class="w-full md:max-w-xl md:flex-1"
>
    <!-- Mobile trigger: looks like the search field, opens the full-screen overlay -->
    <button
        type="button"
        class="flex w-full items-center gap-2 rounded-lg border border-zinc-300 bg-zinc-50 px-3 py-2 text-left text-sm text-zinc-400 md:hidden dark:border-zinc-700 dark:bg-zinc-800"
        x-on:click="mobileOpen = true; open = true; $nextTick(() => $refs.input.focus())"
        aria-label="Search"
    >
        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
        </svg>
        <span class="truncate">{{ $query !== '' ? $query : 'Search for products, brands and more' }}</span>
    </button>

    {{-- Explicit viewport size: the header's backdrop-blur makes it the containing block for fixed children --}}
    <div
        class="hidden md:block"
        x-bind:class="mobileOpen ? '!flex fixed left-0 top-0 z-50 h-dvh w-screen flex-col overflow-y-auto bg-white p-4 dark:bg-zinc-900' : 'relative'"
    >
        <form action="{{ route('products.index') }}" method="GET" role="search" x-on:submit="choose($event)" class="flex items-center gap-2">
            <button
                type="button"
                class="md:hidden rounded-lg p-2 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                x-on:click="close()"
                aria-label="Close search"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
            </button>

            <div class="relative flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
                <input
                    x-ref="input"
                    type="search"
                    name="q"
                    wire:model.live.debounce.250ms="query"
                    x-on:focus="open = true"
                    x-on:input="open = true; active = -1"
                    x-on:keydown.arrow-down.prevent="move(1)"
                    x-on:keydown.arrow-up.prevent="move(-1)"
                    autocomplete="off"
                    maxlength="100"
                    placeholder="Search for products, brands and more"
                    aria-label="Search products"
                    class="w-full rounded-lg border border-zinc-300 bg-zinc-50 py-2 pl-9 pr-3 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-amber-500 focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-amber-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:focus:bg-zinc-900"
                />
            </div>
        </form>

        @php
            $suggestions = $this->suggestions;
            $index = 0;
        @endphp

        <div
            x-ref="panel"
            x-show="open"
            style="display: none"
            class="mt-3 md:absolute md:left-0 md:right-0 md:top-full md:z-50 md:mt-2"
        >
            @if ($suggestions['scopes'] !== [] || $suggestions['categories'] !== [] || $suggestions['products'] !== [])
                <ul class="py-2 text-sm md:max-h-[70vh] md:overflow-y-auto md:rounded-xl md:border md:border-zinc-200 md:bg-white md:shadow-lg dark:md:border-zinc-800 dark:md:bg-zinc-900" role="listbox">
                    @foreach ($suggestions['scopes'] as $scope)
                        <li>
                            <a
                                href="{{ $scope['url'] }}"
                                data-suggestion
                                wire:navigate
                                x-bind:class="active === {{ $index }} ? 'bg-zinc-100 dark:bg-zinc-800' : ''"
                                class="flex items-center gap-3 px-4 py-2 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            >
                                <svg class="h-4 w-4 shrink-0 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                </svg>
                                <span class="truncate">
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $scope['label'] }}</span>
                                    <span class="text-zinc-500 dark:text-zinc-400">in</span>
                                    <span class="font-medium text-amber-600 dark:text-amber-400">{{ $scope['context'] }}</span>
                                </span>
                            </a>
                        </li>
                        @php $index++; @endphp
                    @endforeach

                    @foreach ($suggestions['categories'] as $category)
                        <li>
                            <a
                                href="{{ $category['url'] }}"
                                data-suggestion
                                wire:navigate
                                x-bind:class="active === {{ $index }} ? 'bg-zinc-100 dark:bg-zinc-800' : ''"
                                class="flex items-center gap-3 px-4 py-2 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            >
                                <svg class="h-4 w-4 shrink-0 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                                </svg>
                                <span class="truncate">
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $category['label'] }}</span>
                                    @if ($category['context'])
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">in {{ $category['context'] }}</span>
                                    @endif
                                </span>
                            </a>
                        </li>
                        @php $index++; @endphp
                    @endforeach

                    @if ($suggestions['products'] !== [])
                        <li class="mt-1 border-t border-zinc-100 px-4 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-zinc-400 dark:border-zinc-800">Products</li>
                    @endif

                    @foreach ($suggestions['products'] as $product)
                        <li>
                            <a
                                href="{{ $product['url'] }}"
                                data-suggestion
                                wire:navigate
                                x-bind:class="active === {{ $index }} ? 'bg-zinc-100 dark:bg-zinc-800' : ''"
                                class="flex items-center gap-3 px-4 py-2 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            >
                                <img src="{{ $product['image_url'] }}" alt="" class="h-10 w-10 shrink-0 rounded-md bg-zinc-100 object-cover dark:bg-zinc-800" loading="lazy" />
                                <span class="min-w-0 flex-1 truncate text-zinc-800 dark:text-zinc-200">{{ $product['title'] }}</span>
                                @if ($product['price_paise'] !== null)
                                    <span class="shrink-0 font-semibold text-zinc-900 dark:text-white">₹{{ number_format($product['price_paise'] / 100) }}</span>
                                @endif
                            </a>
                        </li>
                        @php $index++; @endphp
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
