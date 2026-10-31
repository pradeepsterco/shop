@props([
    'seo' => null,
])

@php
    $brandName = function_exists('brand_name') ? brand_name() : config('app.name', 'Marketplace');

    if (! ($seo instanceof \App\SEO\SeoData)) {
        if (is_array($seo)) {
            $seo = \App\SEO\SeoData::make(
                title: $seo['title'] ?? null,
                description: $seo['description'] ?? null,
                canonical: $seo['canonical'] ?? null,
                robots: $seo['robots'] ?? null,
                ogType: $seo['ogType'] ?? null,
                ogImage: $seo['ogImage'] ?? null,
                ogImageAlt: $seo['ogImageAlt'] ?? null,
            );
        } else {
            $seo = \App\SEO\SeoData::make(
                title: is_string($seo) ? $seo : ($title ?? null)
            );
        }
    }

    $titleTag = $seo->formattedTitle($brandName);
    $hasImage = filled($seo->ogImage);
@endphp

<title>{{ $titleTag }}</title>

@if (filled($seo->description))
<meta name="description" content="{{ $seo->description }}">
@endif

@if (filled($seo->canonical))
<link rel="canonical" href="{{ $seo->canonical }}">
@endif

<meta name="robots" content="{{ $seo->robots }}">

<!-- Open Graph / Facebook -->
<meta property="og:type" content="{{ $seo->ogType }}">
<meta property="og:site_name" content="{{ $brandName }}">
<meta property="og:title" content="{{ $seo->title ?: $brandName }}">
@if (filled($seo->description))
<meta property="og:description" content="{{ $seo->description }}">
@endif
@if (filled($seo->canonical))
<meta property="og:url" content="{{ $seo->canonical }}">
@endif
@if ($hasImage)
<meta property="og:image" content="{{ $seo->ogImage }}">
@if (filled($seo->ogImageAlt))
<meta property="og:image:alt" content="{{ $seo->ogImageAlt }}">
@endif
@endif

<!-- Twitter -->
<meta name="twitter:card" content="{{ $hasImage ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $seo->title ?: $brandName }}">
@if (filled($seo->description))
<meta name="twitter:description" content="{{ $seo->description }}">
@endif
@if ($hasImage)
<meta name="twitter:image" content="{{ $seo->ogImage }}">
@endif

