@props([
    'title' => null,
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'w-full space-y-6']) }}>
    @if ($title || isset($action))
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            @if ($title)
                <div>
                    <h1 class="text-xl md:text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                        {{ $title }}
                    </h1>
                    @if ($subtitle)
                        <p class="mt-1 text-xs md:text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $subtitle }}
                        </p>
                    @endif
                </div>
            @endif

            @if (isset($action))
                <div class="flex items-center gap-3 shrink-0">
                    {{ $action }}
                </div>
            @endif
        </div>
    @endif

    {{ $slot }}
</div>

