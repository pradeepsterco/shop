@props([
    'section',
])

@php
    $settings = $section->settings;
    $items = $section->items;
    $item = $items[0] ?? null;
    $layout = $settings['layout'] ?? 'boxed';
    $heightPreset = $settings['height_preset'] ?? 'md';
    $heightClass = match ($heightPreset) {
        'sm' => 'min-h-[140px] sm:min-h-[180px]',
        'lg' => 'min-h-[280px] sm:min-h-[380px]',
        default => 'min-h-[200px] sm:min-h-[260px]',
    };
@endphp

@if ($item)
    @php
        $hasLink = ! empty($item->link?->url);
        $loadingMode = $section->isEager ? 'eager' : 'lazy';
        $opacity = (int) ($item->settings['overlay_opacity'] ?? 30);
    @endphp

    <div class="{{ $layout === 'full_width' ? 'w-full' : 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8' }}">
        <div class="relative w-full overflow-hidden rounded-2xl shadow-sm {{ $heightClass }} bg-zinc-900 flex items-center">
            @if ($hasLink)
                <a
                    href="{{ $item->link->url }}"
                    @if ($item->link->isExternal) target="{{ $item->link->target }}" rel="{{ $item->link->rel }}" @else wire:navigate @endif
                    class="absolute inset-0 size-full group"
                >
            @endif

            <!-- Desktop Image -->
            @if ($item->desktopMedia)
                <div class="hidden sm:block absolute inset-0 size-full">
                    <x-content::sections.media-image
                        :media="$item->desktopMedia"
                        class="size-full object-cover transition duration-300 group-hover:scale-105"
                        :loading="$loadingMode"
                    />
                </div>
                <!-- Mobile Image (Falls back to desktop) -->
                <div class="block sm:hidden absolute inset-0 size-full">
                    <x-content::sections.media-image
                        :media="$item->mobileMedia ?? $item->desktopMedia"
                        class="size-full object-cover transition duration-300 group-hover:scale-105"
                        :loading="$loadingMode"
                    />
                </div>
            @endif

            <!-- Overlay & Text -->
            <div
                class="absolute inset-0 flex items-center p-6 sm:p-12 text-white"
                style="background-color: rgba(0, 0, 0, {{ $opacity / 100 }});"
            >
                @if (! empty($item->settings['headline']) || ! empty($item->settings['subheadline']))
                    <div class="max-w-lg space-y-2">
                        @if (! empty($item->settings['headline']))
                            <h3 class="text-xl sm:text-3xl font-extrabold tracking-tight">
                                {{ $item->settings['headline'] }}
                            </h3>
                        @endif
                        @if (! empty($item->settings['subheadline']))
                            <p class="text-xs sm:text-sm text-zinc-200">
                                {{ $item->settings['subheadline'] }}
                            </p>
                        @endif
                        @if (! empty($item->settings['cta_text']))
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-white text-zinc-900 font-bold text-xs px-4 py-2 shadow hover:bg-zinc-100 transition mt-2">
                                {{ $item->settings['cta_text'] }}
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                            </span>
                        @endif
                    </div>
                @endif
            </div>

            @if ($hasLink)
                </a>
            @endif
        </div>
    </div>
@endif

