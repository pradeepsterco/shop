@props([
    'section',
])

@php
    $settings = $section->settings;
    $items = $section->items;
    $itemCount = count($items);
    $autoplay = $settings['autoplay'] ?? true;
    $interval = $settings['interval_ms'] ?? 5000;
    $showDots = $settings['show_dots'] ?? true;
    $showArrows = $settings['show_arrows'] ?? true;
@endphp

@if ($itemCount > 0)
    <div
        x-data="{
            current: 0,
            total: {{ $itemCount }},
            autoplay: {{ $autoplay ? 'true' : 'false' }},
            interval: {{ $interval }},
            timer: null,
            init() {
                if (this.autoplay && this.total > 1) {
                    this.startAutoplay();
                }
            },
            startAutoplay() {
                this.timer = setInterval(() => {
                    this.next();
                }, this.interval);
            },
            stopAutoplay() {
                if (this.timer) clearInterval(this.timer);
            },
            next() {
                this.current = (this.current + 1) % this.total;
            },
            prev() {
                this.current = (this.current - 1 + this.total) % this.total;
            },
            goTo(index) {
                this.current = index;
            }
        }"
        @mouseenter="stopAutoplay()"
        @mouseleave="if (autoplay) startAutoplay()"
        class="relative w-full overflow-hidden rounded-2xl bg-zinc-900 shadow-sm"
    >
        <div class="relative w-full aspect-[21/9] sm:aspect-[24/9] min-h-[220px]">
            @foreach ($items as $index => $item)
                @php
                    $isFirst = ($index === 0 && $section->isEager);
                    $loadingMode = $isFirst ? 'eager' : 'lazy';
                    $hasLink = ! empty($item->link?->url);
                @endphp
                <div
                    x-show="current === {{ $index }}"
                    x-transition:enter="transition ease-out duration-500"
                    x-transition:enter-start="opacity-0 transform translate-x-4"
                    x-transition:enter-end="opacity-100 transform translate-x-0"
                    x-transition:leave="transition ease-in duration-300"
                    x-transition:leave-start="opacity-100 transform translate-x-0"
                    x-transition:leave-end="opacity-0 transform -translate-x-4"
                    class="absolute inset-0 size-full flex items-center justify-center"
                    @if ($index > 0) x-cloak @endif
                >
                    @if ($hasLink)
                        <a
                            href="{{ $item->link->url }}"
                            @if ($item->link->isExternal) target="{{ $item->link->target }}" rel="{{ $item->link->rel }}" @else wire:navigate @endif
                            class="relative block size-full group"
                        >
                    @else
                        <div class="relative size-full">
                    @endif

                        @if ($item->desktopMedia)
                            <!-- Desktop Image -->
                            <div class="hidden sm:block size-full">
                                <x-content::sections.media-image
                                    :media="$item->desktopMedia"
                                    class="size-full object-cover"
                                    :loading="$loadingMode"
                                />
                            </div>
                            <!-- Mobile Image (Falls back to desktop) -->
                            <div class="block sm:hidden size-full">
                                <x-content::sections.media-image
                                    :media="$item->mobileMedia ?? $item->desktopMedia"
                                    class="size-full object-cover"
                                    :loading="$loadingMode"
                                />
                            </div>
                        @else
                            <div class="size-full flex items-center justify-center bg-gradient-to-r from-amber-500 to-orange-600 text-white p-6">
                                <span class="text-xl font-bold tracking-tight">{{ $item->settings['heading'] ?? 'Featured Marketplace Offer' }}</span>
                            </div>
                        @endif

                        @php
                            $heading = $item->settings['heading'] ?? $item->settings['headline'] ?? null;
                            $subheading = $item->settings['subheading'] ?? null;
                            $ctaText = $item->settings['cta_text'] ?? $item->settings['cta_label'] ?? null;
                        @endphp
                        @if (! empty($heading) || ! empty($subheading))
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/30 to-transparent flex items-end p-6 sm:p-10">
                                <div class="max-w-xl text-white space-y-2">
                                    @if (! empty($heading))
                                        <h2 class="text-xl sm:text-3xl font-black tracking-tight leading-tight drop-shadow-sm">
                                            {{ $heading }}
                                        </h2>
                                    @endif
                                    @if (! empty($subheading))
                                        <p class="text-xs sm:text-sm text-zinc-200 line-clamp-2">
                                            {{ $subheading }}
                                        </p>
                                    @endif
                                    @if (! empty($ctaText))
                                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-amber-500 px-4 py-2 text-xs font-bold text-white shadow hover:bg-amber-600 transition mt-2">
                                            {{ $ctaText }}
                                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endif

                    @if ($hasLink)
                        </a>
                    @else
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($itemCount > 1 && $showArrows)
            <!-- Prev Button -->
            <button
                type="button"
                @click="prev()"
                class="absolute left-3 top-1/2 -translate-y-1/2 flex size-9 items-center justify-center rounded-full bg-black/40 text-white backdrop-blur hover:bg-black/70 transition cursor-pointer"
                aria-label="Previous Slide"
            >
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
            </button>
            <!-- Next Button -->
            <button
                type="button"
                @click="next()"
                class="absolute right-3 top-1/2 -translate-y-1/2 flex size-9 items-center justify-center rounded-full bg-black/40 text-white backdrop-blur hover:bg-black/70 transition cursor-pointer"
                aria-label="Next Slide"
            >
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </button>
        @endif

        @if ($itemCount > 1 && $showDots)
            <div class="absolute bottom-3 left-1/2 -translate-x-1/2 flex items-center gap-1.5 z-10">
                @foreach ($items as $index => $item)
                    <button
                        type="button"
                        @click="goTo({{ $index }})"
                        class="size-2 rounded-full transition-all cursor-pointer"
                        :class="current === {{ $index }} ? 'bg-amber-500 w-6' : 'bg-white/60 hover:bg-white'"
                        aria-label="Go to slide {{ $index + 1 }}"
                    ></button>
                @endforeach
            </div>
        @endif
    </div>
@endif

