@props([
    'section',
])

@php
    $settings = $section->settings;
    $items = $section->items;
    $heading = $settings['heading'] ?? 'Top Deals';
    $subheading = $settings['subheading'] ?? '';
    $badgeText = $settings['badge_text'] ?? '';
@endphp

@if (count($items) > 0)
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <!-- Section Header -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between border-b border-zinc-200 pb-3 dark:border-zinc-800 gap-2">
            <div>
                <div class="flex items-center gap-2">
                    @if ($badgeText)
                        <span class="rounded bg-rose-500 text-white font-extrabold uppercase text-[10px] tracking-wider px-2 py-0.5">
                            {{ $badgeText }}
                        </span>
                    @endif
                    <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                        {{ $heading }}
                    </h2>
                </div>
                @if ($subheading)
                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                        {{ $subheading }}
                    </p>
                @endif
            </div>

            <a href="{{ route('products.index') }}" wire:navigate class="text-xs font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-400 flex items-center gap-1">
                View All
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </a>
        </div>

        <!-- Carousel Track -->
        <div class="flex gap-4 overflow-x-auto pb-4 scrollbar-thin">
            @foreach ($items as $item)
                @php
                    $hasLink = ! empty($item->link?->url);
                    $title = (! empty($item->settings['override_title']))
                        ? $item->settings['override_title']
                        : ($item->settings['title'] ?? $item->settings['live_title'] ?? 'Deal Item');
                    $price = $item->settings['override_price'] ?? ($item->settings['live_price'] ?? ($item->settings['price'] ?? null));
                    $mrp = $item->settings['live_mrp'] ?? null;
                    $discount = $item->settings['live_discount'] ?? null;
                    $isSponsored = $item->settings['is_sponsored'] ?? false;
                @endphp

                <div class="w-48 sm:w-56 shrink-0 rounded-2xl border border-zinc-200 bg-white p-3 shadow-xs hover:shadow-md transition dark:border-zinc-800 dark:bg-zinc-900 flex flex-col justify-between">
                    @if ($hasLink)
                        <a
                            href="{{ $item->link->url }}"
                            @if ($item->link->isExternal) target="{{ $item->link->target }}" rel="{{ $item->link->rel }}" @else wire:navigate @endif
                            class="group block space-y-3"
                        >
                    @else
                        <div class="space-y-3">
                    @endif

                        <!-- Product / Deal Image -->
                        <div class="relative w-full aspect-square overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-800">
                            @if ($item->desktopMedia)
                                <x-content::sections.media-image
                                    :media="$item->desktopMedia"
                                    class="size-full object-cover group-hover:scale-105 transition duration-300"
                                    loading="lazy"
                                />
                            @else
                                <div class="size-full flex items-center justify-center text-zinc-400">
                                    <svg class="size-10 stroke-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/></svg>
                                </div>
                            @endif

                            @if ($discount && $discount > 0)
                                <span class="absolute top-2 left-2 rounded-md bg-emerald-600 text-white font-extrabold text-[10px] px-1.5 py-0.5 shadow">
                                    {{ $discount }}% OFF
                                </span>
                            @endif

                            @if ($isSponsored)
                                <span class="absolute bottom-2 right-2 rounded bg-black/60 text-white text-[9px] px-1 py-0.5 backdrop-blur">
                                    Sponsored
                                </span>
                            @endif
                        </div>

                        <!-- Title -->
                        <div>
                            <h4 class="text-xs sm:text-sm font-semibold text-zinc-900 group-hover:text-amber-600 transition dark:text-white dark:group-hover:text-amber-400 line-clamp-2">
                                {{ $title }}
                            </h4>
                        </div>

                    @if ($hasLink)
                        </a>
                    @else
                        </div>
                    @endif

                    <!-- Price Footer -->
                    <div class="mt-2 pt-2 border-t border-zinc-100 dark:border-zinc-800 flex items-baseline gap-2">
                        @if ($price !== null)
                            <span class="text-sm sm:text-base font-black text-zinc-900 dark:text-white">
                                ₹{{ number_format($price / 100, 2) }}
                            </span>
                        @endif
                        @if ($mrp && $price && $mrp > $price)
                            <span class="text-xs text-zinc-400 line-through">
                                ₹{{ number_format($mrp / 100, 2) }}
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif

