@props([
    'section',
])

@php
    $settings = $section->settings;
    $items = $section->items;
    $heading = $settings['heading'] ?? '';
    $style = $settings['display_style'] ?? 'circular';
    $showLabels = $settings['show_labels'] ?? true;
@endphp

@if (count($items) > 0)
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (! empty($heading))
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-zinc-900 dark:text-white border-b border-zinc-200 pb-3 dark:border-zinc-800">
                {{ $heading }}
            </h2>
        @endif

        <div class="flex items-center gap-4 overflow-x-auto pb-2 scrollbar-none sm:grid sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 sm:overflow-visible">
            @foreach ($items as $item)
                @php
                    $hasLink = ! empty($item->link?->url);
                    $label = $item->settings['custom_label'] ?? 'Category';
                @endphp
                @if ($hasLink)
                    <a
                        href="{{ $item->link->url }}"
                        @if ($item->link->isExternal) target="{{ $item->link->target }}" rel="{{ $item->link->rel }}" @else wire:navigate @endif
                        class="group flex flex-col items-center shrink-0 w-20 sm:w-auto text-center"
                    >
                @else
                    <div class="flex flex-col items-center shrink-0 w-20 sm:w-auto text-center">
                @endif

                    @if ($style === 'circular')
                        <div class="size-16 sm:size-20 rounded-full border border-zinc-200 bg-white p-2 shadow-xs group-hover:border-amber-500 group-hover:scale-105 transition dark:border-zinc-800 dark:bg-zinc-900 flex items-center justify-center overflow-hidden">
                            @if ($item->desktopMedia)
                                <x-content::sections.media-image
                                    :media="$item->desktopMedia"
                                    class="size-full object-contain"
                                    loading="lazy"
                                />
                            @else
                                <svg class="size-8 text-zinc-400 group-hover:text-amber-500 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72m-13.5 8.651h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.651c0 .414.336.75.75.75Z"/></svg>
                            @endif
                        </div>
                    @elseif ($style === 'pill')
                        <div class="px-4 py-2 rounded-full border border-zinc-200 bg-white shadow-xs group-hover:border-amber-500 group-hover:bg-amber-50 dark:border-zinc-800 dark:bg-zinc-900 dark:group-hover:bg-zinc-800 transition flex items-center gap-2">
                            @if ($item->desktopMedia)
                                <div class="size-5 shrink-0 overflow-hidden rounded-full">
                                    <x-content::sections.media-image :media="$item->desktopMedia" class="size-full object-cover" loading="lazy" />
                                </div>
                            @endif
                            <span class="text-xs font-semibold text-zinc-800 group-hover:text-amber-600 dark:text-zinc-200 truncate">{{ $label }}</span>
                        </div>
                    @else
                        <!-- Card Style -->
                        <div class="w-full rounded-xl border border-zinc-200 bg-white p-3 shadow-xs group-hover:border-amber-500 group-hover:shadow-sm transition dark:border-zinc-800 dark:bg-zinc-900 flex flex-col items-center">
                            <div class="size-12 overflow-hidden mb-1 flex items-center justify-center">
                                @if ($item->desktopMedia)
                                    <x-content::sections.media-image :media="$item->desktopMedia" class="size-full object-contain" loading="lazy" />
                                @else
                                    <svg class="size-6 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/></svg>
                                @endif
                            </div>
                            <span class="text-xs font-bold text-zinc-800 group-hover:text-amber-600 dark:text-zinc-200 truncate w-full text-center">{{ $label }}</span>
                        </div>
                    @endif

                    @if ($style === 'circular' && $showLabels && ! empty($label))
                        <span class="mt-2 text-xs font-semibold text-zinc-700 group-hover:text-amber-600 dark:text-zinc-300 dark:group-hover:text-amber-400 line-clamp-1">
                            {{ $label }}
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

