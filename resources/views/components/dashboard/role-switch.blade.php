@props(['role' => 'user'])

@if ($role === 'seller')
    <a href="{{ route('dashboard') }}"
       wire:navigate
       data-test="role-switch-button"
       class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 sm:px-3 text-xs font-semibold text-zinc-700 shadow-xs hover:bg-zinc-50 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 dark:hover:text-white transition cursor-pointer">
        <svg class="size-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
        </svg>
        <span class="hidden sm:inline">Switch to customer</span>
        <span class="sm:hidden">Customer</span>
    </a>
@elseif ($role === 'user' && auth()->check())
    @php
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $sellerProfile = $user->sellerProfile;
        $isApproved = $user->isSeller();

        if (! $sellerProfile) {
            $switchLabel = 'Become a seller';
            $mobileLabel = 'Sell';
            $switchRoute = route('seller.apply');
        } elseif (! $isApproved) {
            $switchLabel = brand_name('seller');
            $mobileLabel = brand_name('seller');
            $switchRoute = route('seller.apply');
        } else {
            $switchLabel = 'Switch to seller';
            $mobileLabel = 'Seller';
            $switchRoute = route('seller.dashboard');
        }
    @endphp

    <a href="{{ $switchRoute }}"
       wire:navigate
       data-test="role-switch-button"
       class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 sm:px-3 text-xs font-semibold text-zinc-700 shadow-xs hover:bg-zinc-50 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 dark:hover:text-white transition cursor-pointer">
        <svg class="size-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72m-13.5 8.651h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.651c0 .414.336.75.75.75Z" />
        </svg>
        <span class="hidden sm:inline">{{ $switchLabel }}</span>
        <span class="sm:hidden">{{ $mobileLabel }}</span>
    </a>
@elseif ($role === 'admin')
    <a href="{{ route('products.index') }}"
       wire:navigate
       class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 sm:px-3 text-xs font-semibold text-zinc-700 shadow-xs hover:bg-zinc-50 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 dark:hover:text-white transition cursor-pointer">
        <svg class="size-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
        </svg>
        <span class="hidden sm:inline">Storefront</span>
    </a>
@endif

