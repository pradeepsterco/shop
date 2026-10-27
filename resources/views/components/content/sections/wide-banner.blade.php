@props([
    'section',
])

@php
    $settings = $section->settings;
    $items = $section->items;
    $item = $items[0] ?? null;
    $bgColor = $settings['background_color'] ?? '';
    $textAlign = $settings['text_alignment'] ?? 'left';
    $alignClass = match ($textAlign) {
        'center' => 'items-center text-center mx-auto',
        'right' => 'items-end text-right ml-auto',
        default => 'items-start text-left mr-auto',
    };
@endphp

@if ($item)
    @php
        $hasLink = ! empty($item->link?->url);
        $loadingMode = $section->isEager ? 'eager' : 'lazy';
    @endphp

    <div
        class="relative w-full overflow-hidden bg-zinc-900 min-h-[220px] sm:min-h-[280px] flex items-center shadow-xs"
        @if ($bgColor) style="background-color: {{ $bgColor }};" @endif
    >
        @if ($hasLink)
            <a
                href="{{ $item->link->url }}"
                @if ($item->link->isExternal) target="{{ $item->link->target }}" rel="{{ $item->link->rel }}" @else wire:navigate @endif
                class="absolute inset-0 size-full group"
            >
        @endif

        @if ($item->desktopMedia)
            <div class="hidden sm:block absolute inset-0 size-full">
                <x-content::sections.media-image
                    :media="$item->desktopMedia"
                    class="size-full object-cover transition duration-300 group-hover:scale-105"
                    :loading="$loadingMode"
                />
            </div>
            <div class="block sm:hidden absolute inset-0 size-full">
                <x-content::sections.media-image
                    :media="$item->mobileMedia ?? $item->desktopMedia"
                    class="size-full object-cover transition duration-300 group-hover:scale-105"
                    :loading="$loadingMode"
                />
            </div>
        @endif

        <div class="relative max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
            <div class="max-w-xl flex flex-col {{ $alignClass }} text-white space-y-2.5">
                @if (! empty($item->settings['badge_text']))
                    <span class="inline-block rounded-md bg-amber-500/90 text-white font-bold uppercase tracking-wider text-[11px] px-2.5 py-1">
                        {{ $item->settings['badge_text'] }}
                    </span>
                @endif
                @if (! empty($item->settings['heading']))
                    <h2 class="text-2xl sm:text-4xl font-black tracking-tight leading-tight">
                        {{ $item->settings['heading'] }}
                    </h2>
                @endif
                @if (! empty($item->settings['subheading']))
                    <p class="text-xs sm:text-sm text-zinc-200">
                        {{ $item->settings['subheading'] }}
                    </p>
                @endif
                @if (! empty($item->settings['cta_text']))
                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-white text-zinc-950 font-bold text-xs px-5 py-2.5 shadow hover:bg-zinc-100 transition mt-2">
                        {{ $item->settings['cta_text'] }}
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                    </span>
                @endif
            </div>
        </div>

        @if ($hasLink)
            </a>
        @endif
    </div>
@endif

