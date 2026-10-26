<div x-data="{
    appearance: (window.Flux && window.Flux.appearance) || window.localStorage.getItem('flux.appearance') || 'system',
    init() {
        window.addEventListener('storage', (e) => {
            if (e.key === 'flux.appearance') {
                this.appearance = e.newValue || 'system';
            }
        });
        document.addEventListener('livewire:navigated', () => {
            this.appearance = (window.Flux && window.Flux.appearance) || window.localStorage.getItem('flux.appearance') || 'system';
        });
    },
    setAppearance(val) {
        this.appearance = val;

        // 1. Update localStorage
        if (val === 'system') {
            window.localStorage.removeItem('flux.appearance');
        } else {
            window.localStorage.setItem('flux.appearance', val);
        }

        // 2. Update Flux reactive store if available so Flux effects and livewire:navigated use the new value
        if (window.Flux && 'appearance' in window.Flux) {
            window.Flux.appearance = val;
        }

        // 3. Immediately apply to document.documentElement
        const isDark = val === 'dark' || (val === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', isDark);

        // 4. Call Flux applyAppearance if available
        if (window.Flux && typeof window.Flux.applyAppearance === 'function') {
            window.Flux.applyAppearance(val);
        }
    }
}" class="inline-flex items-center rounded-lg border border-zinc-200 bg-zinc-100/90 p-0.5 dark:border-zinc-800 dark:bg-zinc-800/90 text-zinc-600 dark:text-zinc-400">
    <button type="button"
            @click="setAppearance('light')"
            :class="appearance === 'light' ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white font-semibold' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
            class="flex items-center justify-center size-7 rounded-md transition cursor-pointer"
            title="Light theme"
            aria-label="Light theme">
        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
        </svg>
    </button>
    <button type="button"
            @click="setAppearance('dark')"
            :class="appearance === 'dark' ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white font-semibold' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
            class="flex items-center justify-center size-7 rounded-md transition cursor-pointer"
            title="Dark theme"
            aria-label="Dark theme">
        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
        </svg>
    </button>
    <button type="button"
            @click="setAppearance('system')"
            :class="appearance === 'system' ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white font-semibold' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
            class="flex items-center justify-center size-7 rounded-md transition cursor-pointer"
            title="System theme"
            aria-label="System theme">
        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
        </svg>
    </button>
</div>

