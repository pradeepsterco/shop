@props([
    'area' => 'storefront',
    'isEmail' => false,
    'size' => 'md',
    'class' => '',
])

@php
    $lightUrl = brand_logo_url($area, dark: false, absolute: $isEmail);
    $darkUrl = $isEmail ? null : brand_logo_url($area, dark: true, absolute: false);
    $name = brand_name($area);
    $hasLogo = filled($lightUrl);

    $heightClass = match($size) {
        'sm' => 'h-6',
        'lg' => 'h-10',
        default => 'h-8',
    };
@endphp

@if ($hasLogo)
    @if ($isEmail)
        <img src="{{ $lightUrl }}" alt="{{ brand_name() }}" style="max-height: 40px; width: auto; display: block;" />
    @elseif ($darkUrl && $darkUrl !== $lightUrl)
        <img src="{{ $lightUrl }}" alt="{{ $name }}" class="{{ $heightClass }} w-auto object-contain dark:hidden {{ $class }}" />
        <img src="{{ $darkUrl }}" alt="{{ $name }}" class="{{ $heightClass }} w-auto object-contain hidden dark:block {{ $class }}" />
    @else
        <img src="{{ $lightUrl }}" alt="{{ $name }}" class="{{ $heightClass }} w-auto object-contain {{ $class }}" />
    @endif
@else
    @if ($isEmail)
        <span style="font-size: 20px; font-weight: bold; color: #18181b;">{{ $name }}</span>
    @else
        <span class="inline-flex items-center gap-2 font-bold tracking-tight text-zinc-900 dark:text-white {{ $class }}">
            @if ($area === 'seller')
                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-600 text-white font-black text-sm">S</span>
            @elseif ($area === 'admin')
                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-900 text-white dark:bg-white dark:text-zinc-900 text-sm font-black">A</span>
            @else
                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-amber-500 text-white font-black text-sm">
                    {{ substr($name, 0, 1) }}
                </span>
            @endif
            <span class="truncate">{{ $name }}</span>
        </span>
    @endif
@endif

