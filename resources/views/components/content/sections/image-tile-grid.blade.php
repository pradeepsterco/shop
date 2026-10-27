@props([
    'section',
])

@php
    $settings = $section->settings;
    $items = $section->items;
    $heading = $settings['heading'] ?? '';
    $columns = (int) ($settings['columns'] ?? 4);
    $aspectRatio = $settings['aspect_ratio'] ?? '1:1';
    $gap = $settings['gap'] ?? 'md';

    $colClass = match ($columns) {
        2 => 'grid-cols-1 sm:grid-cols-2',
        3 => 'grid-cols-1 sm:grid-cols-2 md:grid-cols-3',
        6 => 'grid-cols-2 sm:grid-cols-3 md:grid-cols-6',
        default => 'grid-cols-2 sm:grid-cols-3 md:grid-cols-4',
    };

    $gapClass = match ($gap) {
        'sm' => 'gap-3',
        'lg' => 'gap-6',
        default => 'gap-4',
    };

    $aspectClass = match ($aspectRatio) {
        '4:3' => 'aspect-[4/3]',
        '16:9' => 'aspect-[16/9]',
        default => 'aspect-square',
    };
@endphp

@if (count($items) > 0)
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (! empty($heading))
            <div class="flex items-center justify-between border-b border-zinc-200 pb-3 dark:border-zinc-800">
                <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                    {{ $heading }}
                </h2>
            </div>
        @endif

        <div class="grid {{ $colClass }} {{ $gapClass }}">
            @foreach ($items as $item)
                @php
                    $hasLink = ! empty($item->link?->url);
                @endphp
                @if ($hasLink)
                    <a
                        href="{{ $item->link->url }}"
                        @if ($item->link->isExternal) target="{{ $item->link->target }}" rel="{{ $item->link->rel }}" @else wire:navigate @endif
                        class="group relative overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 hover:shadow-md transition"
                    >
                @else
                    <div class="group relative overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                @endif

                    <div class="w-full overflow-hidden {{ $aspectClass }} bg-zinc-100 dark:bg-zinc-800">
                        @if ($item->desktopMedia)
                            <x-content::sections.media-image
                                :media="$item->desktopMedia"
                                class="size-full object-cover transition duration-300 group-hover:scale-105"
                                loading="lazy"
                            />
                        @else
                            <div class="size-full flex items-center justify-center text-zinc-400">
                                <svg class="size-8 stroke-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            </div>
                        @endif
                    </div>

                    @if (! empty($item->settings['label']) || ! empty($item->settings['sublabel']))
                        <div class="p-3 text-center sm:text-left">
                            @if (! empty($item->settings['label']))
                                <h4 class="text-sm font-bold text-zinc-900 group-hover:text-amber-600 transition dark:text-white dark:group-hover:text-amber-400 truncate">
                                    {{ $item->settings['label'] }}
                                </h4>
                            @endif
                            @if (! empty($item->settings['sublabel']))
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 truncate">
                                    {{ $item->settings['sublabel'] }}
                                </p>
                            @endif
                        </div>
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

