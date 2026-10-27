@props([
    'media' => null,
    'class' => '',
    'loading' => 'lazy',
    'alt' => null,
])

@if ($media)
    <picture>
        @if (! empty($media->webpUrl))
            <source srcset="{{ $media->webpUrl }}" type="image/webp">
        @endif
        <img
            src="{{ $media->url }}"
            alt="{{ $alt ?? $media->alt }}"
            @if ($media->width > 0) width="{{ $media->width }}" @endif
            @if ($media->height > 0) height="{{ $media->height }}" @endif
            loading="{{ $loading }}"
            decoding="{{ $loading === 'eager' ? 'sync' : 'async' }}"
            {{ $attributes->merge(['class' => $class]) }}
        />
    </picture>
@endif

