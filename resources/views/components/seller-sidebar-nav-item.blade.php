@props([
    'route' => null,
    'label',
    'active' => false,
    'locked' => false,
])

@if ($locked)
    <div aria-disabled="true"
         title="Unlocks when your store is approved"
         aria-label="Unlocks when your store is approved"
         class="flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium opacity-60 cursor-not-allowed select-none text-zinc-500 dark:text-zinc-400">
        <div class="flex items-center gap-3">
            <div class="size-5 shrink-0 blur-[1px] text-zinc-400 dark:text-zinc-500 [&>svg]:size-5">
                {{ $icon }}
            </div>
            <span class="text-zinc-700 dark:text-zinc-300">{{ $label }}</span>
        </div>
        <svg class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
        </svg>
    </div>
@else
    <a href="{{ $route }}"
       wire:navigate
       {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition ' . ($active ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white')]) }}>
        <div class="size-5 shrink-0 [&>svg]:size-5">
            {{ $icon }}
        </div>
        <span>{{ $label }}</span>
        {{ $slot }}
    </a>
@endif

