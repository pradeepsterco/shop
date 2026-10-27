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
        <div class="prose prose-zinc dark:prose-invert max-w-none text-sm sm:text-base leading-relaxed">
            {!! $html !!}
        </div>
    </div>
</div>

