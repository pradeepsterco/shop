@props([
    'section',
])

@php
    $settings = $section->settings;
    $items = $section->items;
    $heading = $settings['heading'] ?? 'Featured Brands';
    $columns = (int) ($settings['columns'] ?? 6);
    $colClass = match ($columns) {
        4 => 'grid-cols-2 sm:grid-cols-4',
        5 => 'grid-cols-2 sm:grid-cols-3 md:grid-cols-5',
        default => 'grid-cols-2 sm:grid-cols-3 md:grid-cols-6',
    };
@endphp

@if (count($items) > 0)
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (! empty($heading))
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-zinc-900 dark:text-white border-b border-zinc-200 pb-3 dark:border-zinc-800">
                {{ $heading }}
            </h2>
        @endif

        <div class="grid {{ $colClass }} gap-4">
            @foreach ($items as $item)
                @php
                    $hasLink = ! empty($item->link?->url);
                    $brandName = $item->settings['custom_name'] ?? $item->settings['brand_name'] ?? 'Brand';
                @endphp
                @if ($hasLink)
                    <a
                        href="{{ $item->link->url }}"
                        @if ($item->link->isExternal) target="{{ $item->link->target }}" rel="{{ $item->link->rel }}" @else wire:navigate @endif
                        class="group flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white p-4 shadow-xs hover:border-amber-500 hover:shadow-md transition dark:border-zinc-800 dark:bg-zinc-900"
                    >
                @else
                    <div class="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                @endif

                    <div class="h-12 w-full flex items-center justify-center overflow-hidden">
                        @if ($item->desktopMedia)
                            <x-content::sections.media-image
                                :media="$item->desktopMedia"
                                class="max-h-full max-w-full object-contain filter grayscale group-hover:grayscale-0 transition duration-300"
                                loading="lazy"
                            />
                        @else
                            <span class="text-sm font-black text-zinc-700 dark:text-zinc-300 group-hover:text-amber-500 transition">
                                {{ $brandName }}
                            </span>
                        @endif
                    </div>

                    @if (! empty($item->settings['custom_name']))
                        <span class="mt-2 text-[11px] font-medium text-zinc-500 group-hover:text-zinc-900 dark:text-zinc-400 dark:group-hover:text-white transition truncate max-w-full">
                            {{ $item->settings['custom_name'] }}
                        </span>
                    @endif

                @if ($hasLink)
                    </a>
                @else
                    </div>
                @endif
            @endforeach
        </div>
    </div>
@endif

