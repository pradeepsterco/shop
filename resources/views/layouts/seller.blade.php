<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ filled($title ?? null) ? $title.' - '.brand_name('seller') : 'Dashboard - '.brand_name('seller') }}</title>

    <x-brand.favicon />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.theme-script')
</head>
<body class="min-h-screen bg-zinc-100 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    @php
        $sellerUser = auth()->user();
        $sellerProfile = $sellerUser?->sellerProfile;
        $sellerStatus = $sellerProfile?->status;
        $isApproved = $sellerStatus === \App\Enums\SellerStatus::Approved;
        $isSuspended = $sellerStatus === \App\Enums\SellerStatus::Suspended;
    @endphp

    <div x-data="{ mobileMenuOpen: false }" class="flex min-h-screen">
        <!-- Desktop Sidebar -->
        <aside data-test="seller-sidebar" class="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0 border-r border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 z-30">
            <div class="flex h-16 shrink-0 items-center gap-3 px-6 border-b border-zinc-200 dark:border-zinc-800">
                <a href="{{ $isApproved ? route('seller.dashboard') : route('seller.apply') }}" wire:navigate class="flex items-center gap-2 font-bold text-lg text-zinc-900 dark:text-white truncate">
                    <x-brand.logo area="seller" />
                </a>
            </div>

            <div class="flex flex-1 flex-col justify-between overflow-y-auto px-4 py-6">
                <div>
                    {{-- Status Card --}}
                    <x-seller-sidebar-status-card :profile="$sellerProfile" />

                    <nav class="space-y-1">
                        {{-- Overview --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.dashboard')"
                            label="Overview"
                            :active="request()->routeIs('seller.dashboard')"
                            :locked="! $isApproved">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>

                        {{-- Orders --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.orders.index')"
                            label="Orders"
                            :active="request()->routeIs('seller.orders.*')"
                            :locked="! $isApproved && ! $isSuspended">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                                </svg>
                            </x-slot:icon>
                            @if ($isApproved || $isSuspended)
                                <x-seller-placed-orders-badge />
                            @endif
                        </x-seller-sidebar-nav-item>

                        {{-- My Products --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.products.index')"
                            label="My Products"
                            :active="request()->routeIs('seller.products.index') || request()->routeIs('seller.products.edit')"
                            :locked="! $isApproved">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>

                        {{-- Add Product --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.products.create')"
                            label="Add Product"
                            :active="request()->routeIs('seller.products.create')"
                            :locked="! $isApproved">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>

                        {{-- Fee Calculator (always unlocked) --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.fees.calculator')"
                            label="Fee Calculator"
                            :active="request()->routeIs('seller.fees.calculator')"
                            :locked="false">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>

                        {{-- Payments & Settlements --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.payments.index')"
                            label="Payments & Settlements"
                            :active="request()->routeIs('seller.payments.*')"
                            :locked="! $isApproved && ! $isSuspended">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>

                        {{-- Store Profile --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.profile')"
                            label="Store Profile"
                            :active="request()->routeIs('seller.profile')"
                            :locked="! $isApproved">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>

                        {{-- Settings --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.settings')"
                            label="Settings"
                            :active="request()->routeIs('seller.settings')"
                            :locked="! $isApproved">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>
                    </nav>
                </div>

                <div class="space-y-2 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                                class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                            <span>Log out</span>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <!-- Mobile Drawer Menu -->
        <div x-show="mobileMenuOpen"
             data-test="seller-mobile-menu"
             x-cloak
             class="fixed inset-0 z-50 flex md:hidden"
             role="dialog"
             aria-modal="true">
            <div x-show="mobileMenuOpen"
                 x-transition:enter="transition-opacity ease-linear duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-linear duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 @click="mobileMenuOpen = false"
                 class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div x-show="mobileMenuOpen"
                 x-transition:enter="transition ease-in-out duration-200 transform"
                 x-transition:enter-start="-translate-x-full"
                 x-transition:enter-end="translate-x-0"
                 x-transition:leave="transition ease-in-out duration-200 transform"
                 x-transition:leave-start="translate-x-0"
                 x-transition:leave-end="-translate-x-full"
                 class="relative flex w-full max-w-xs flex-1 flex-col bg-white pt-5 pb-4 dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-800">
                <div class="flex items-center justify-between px-6 pb-4 border-b border-zinc-200 dark:border-zinc-800">
                    <a href="{{ $isApproved ? route('seller.dashboard') : route('seller.apply') }}" @click="mobileMenuOpen = false" wire:navigate class="flex items-center gap-2 font-bold text-lg text-zinc-900 dark:text-white truncate">
                        <x-brand.logo area="seller" />
                    </a>
                    <button type="button" @click="mobileMenuOpen = false" class="p-1 rounded-md text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-white">
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="mt-4 flex-1 px-4 overflow-y-auto">
                    {{-- Status Card Mobile --}}
                    <x-seller-sidebar-status-card :profile="$sellerProfile" />

                    <nav class="space-y-1">
                        {{-- Overview --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.dashboard')"
                            label="Overview"
                            :active="request()->routeIs('seller.dashboard')"
                            :locked="! $isApproved"
                            @click="mobileMenuOpen = false">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>

                        {{-- Orders --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.orders.index')"
                            label="Orders"
                            :active="request()->routeIs('seller.orders.*')"
                            :locked="! $isApproved && ! $isSuspended"
                            @click="mobileMenuOpen = false">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                                </svg>
                            </x-slot:icon>
                            @if ($isApproved || $isSuspended)
                                <x-seller-placed-orders-badge />
                            @endif
                        </x-seller-sidebar-nav-item>

                        {{-- My Products --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.products.index')"
                            label="My Products"
                            :active="request()->routeIs('seller.products.index') || request()->routeIs('seller.products.edit')"
                            :locked="! $isApproved"
                            @click="mobileMenuOpen = false">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>

                        {{-- Add Product --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.products.create')"
                            label="Add Product"
                            :active="request()->routeIs('seller.products.create')"
                            :locked="! $isApproved"
                            @click="mobileMenuOpen = false">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>

                        {{-- Fee Calculator --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.fees.calculator')"
                            label="Fee Calculator"
                            :active="request()->routeIs('seller.fees.calculator')"
                            :locked="false"
                            @click="mobileMenuOpen = false">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>

                        {{-- Payments & Settlements --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.payments.index')"
                            label="Payments & Settlements"
                            :active="request()->routeIs('seller.payments.*')"
                            :locked="! $isApproved && ! $isSuspended"
                            @click="mobileMenuOpen = false">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>

                        {{-- Store Profile --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.profile')"
                            label="Store Profile"
                            :active="request()->routeIs('seller.profile')"
                            :locked="! $isApproved"
                            @click="mobileMenuOpen = false">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>

                        {{-- Settings --}}
                        <x-seller-sidebar-nav-item
                            :route="route('seller.settings')"
                            label="Settings"
                            :active="request()->routeIs('seller.settings')"
                            :locked="! $isApproved"
                            @click="mobileMenuOpen = false">
                            <x-slot:icon>
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                            </x-slot:icon>
                        </x-seller-sidebar-nav-item>
                    </nav>
                </div>

                <div class="space-y-2 border-t border-zinc-200 p-4 dark:border-zinc-800">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                            <span>Log out</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="flex flex-1 flex-col md:pl-64 min-w-0">
            <!-- Top Bar -->
            <x-dashboard.topbar :title="$title ?? brand_name('seller')" role="seller" guard="web" />

            <!-- Main Body -->
            <main class="flex-1 p-4 md:p-8 max-w-7xl w-full mx-auto">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
