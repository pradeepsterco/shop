@php
    $faviconUrl = brand_favicon_url();
    $isCustom = ! str_ends_with($faviconUrl, '/favicon.ico') && ! str_ends_with($faviconUrl, '/favicon.svg');
@endphp

@if ($isCustom)
    <link rel="icon" href="{{ $faviconUrl }}">
@else
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
@endif

