@props([
    'title' => null,
    'role' => 'user',
    'guard' => 'web',
])

<header class="sticky top-0 z-20 flex h-16 shrink-0 items-center justify-between border-b border-zinc-200 bg-white/80 px-4 md:px-8 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-900/80">
    <div class="flex items-center gap-3 min-w-0">
        <button type="button"
                @click="mobileMenuOpen = true"
                class="p-2 rounded-lg text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 md:hidden dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white transition cursor-pointer"
                aria-label="Open sidebar">
            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>
        <h1 class="text-base md:text-lg font-semibold text-zinc-900 dark:text-white truncate">
            {{ $title ?? ($role === 'admin' ? brand_name('admin') : ($role === 'seller' ? brand_name('seller') : 'Dashboard')) }}
        </h1>
    </div>

    <div class="flex items-center gap-2 sm:gap-3">
        {{-- Slot 1: Role switch (Customer <-> Seller / Storefront) --}}
        <x-dashboard.role-switch :role="$role" />

        {{-- Slot 2: Appearance switcher (Light / Dark / System) --}}
        <x-theme-switcher />

        {{-- Slot 3: Account menu / Logout (Far right) --}}
        <x-dashboard.account-menu :guard="$guard" :role="$role" />
    </div>
</header>

