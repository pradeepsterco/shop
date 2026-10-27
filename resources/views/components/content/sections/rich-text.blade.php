@props([
    'section',
])

@php
    $settings = $section->settings;
    $html = $settings['rendered_html'] ?? '';
    $width = $settings['container_width'] ?? 'standard';
    $containerClass = match ($width) {
        'narrow' => 'max-w-3xl',
        'full' => 'w-full',
        default => 'max-w-5xl',
    };
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="{{ $containerClass }} mx-auto py-6">
        <div class="prose prose-zinc dark:prose-invert max-w-none text-sm sm:text-base leading-relaxed text-zinc-900 dark:text-zinc-100 [&_h1]:text-zinc-950 dark:[&_h1]:text-white [&_h1]:font-black [&_h1]:text-2xl sm:[&_h1]:text-3xl [&_h1]:mb-4 [&_h2]:text-zinc-900 dark:[&_h2]:text-zinc-100 [&_h2]:font-bold [&_h2]:text-xl sm:[&_h2]:text-2xl [&_h2]:mt-6 [&_h2]:mb-3 [&_h3]:text-zinc-900 dark:[&_h3]:text-zinc-100 [&_h3]:font-bold [&_h3]:text-lg [&_h3]:mt-4 [&_h3]:mb-2 [&_strong]:text-zinc-950 dark:[&_strong]:text-white [&_strong]:font-bold [&_a]:text-amber-600 dark:[&_a]:text-amber-400 [&_a]:underline [&_p]:text-zinc-700 dark:[&_p]:text-zinc-300 [&_p]:my-3 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:my-3 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:my-3 [&_li]:text-zinc-700 dark:[&_li]:text-zinc-300 [&_li]:my-1">
            {!! $html !!}
        </div>
    </div>
</div>

