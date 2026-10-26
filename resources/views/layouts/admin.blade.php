<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ filled($title ?? null) ? $title.' - Admin - '.config('app.name', 'Laravel') : 'Admin - '.config('app.name', 'Laravel') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <div x-data="{ mobileMenuOpen: false }" class="flex min-h-screen">
        <!-- Desktop Sidebar -->
        <aside class="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0 border-r border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 z-30">
            <div class="flex h-16 shrink-0 items-center gap-3 px-6 border-b border-zinc-200 dark:border-zinc-800">
                <a href="{{ route('admin.dashboard') }}" wire:navigate class="flex items-center gap-2 font-bold text-lg text-zinc-900 dark:text-white">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-zinc-900 text-white dark:bg-white dark:text-zinc-900 text-sm font-black">A</span>
                    <span>Admin Panel</span>
                </a>
            </div>

            <div class="flex flex-1 flex-col justify-between overflow-y-auto px-4 py-6">
                <nav class="space-y-1">
                    <a href="{{ route('admin.dashboard') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.dashboard') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>Dashboard</span>
                    </a>

                    @can('customers.view')
                    <a href="{{ route('admin.customers') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.customers*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                        <span>Customers</span>
                    </a>
                    @endcan

                    @can('sellers.view')
                    <a href="{{ route('admin.sellers.index') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.sellers*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                        <span>Sellers</span>
                    </a>
                    @endcan

                    @can('orders.view')
                    <a href="{{ route('admin.orders.index') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.orders.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                        </svg>
                        <span>Orders</span>
                    </a>
                    @endcan

                    @canany(['catalog.categories', 'catalog.verticals', 'catalog.brands.manage', 'catalog.products.view'])
                    <div class="pt-3 pb-1">
                        <span class="px-3 text-[10px] font-bold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Catalog</span>
                    </div>

                    @can('catalog.categories')
                    <a href="{{ route('admin.catalog.categories') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.catalog.categories*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                        </svg>
                        <span>Categories</span>
                    </a>
                    @endcan

                    @can('catalog.verticals')
                    <a href="{{ route('admin.catalog.verticals') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.catalog.verticals*') || request()->routeIs('admin.catalog.vertical-attributes*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                        </svg>
                        <span>Verticals</span>
                    </a>
                    @endcan

                    @can('catalog.brands.manage')
                    <a href="{{ route('admin.catalog.brands') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.catalog.brands*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                        </svg>
                        <span>Brands</span>
                    </a>
                    @endcan

                    @can('catalog.products.view')
                    <a href="{{ route('admin.catalog.products') }}"
                       wire:navigate
                       class="flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.catalog.products*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <div class="flex items-center gap-3">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                            <span>Products & QC</span>
                        </div>
                        @php
                            $pendingQcCount = \App\Models\Product::where('qc_status', \App\Enums\ProductQcStatus::QcPending)->count();
                        @endphp
                        @if ($pendingQcCount > 0)
                            <span class="inline-flex size-5 items-center justify-center rounded-full bg-amber-500 text-[10px] font-bold text-white">
                                {{ $pendingQcCount }}
                            </span>
                        @endif
                    </a>
                    @endcan
                    @endcanany

                    <div class="pt-3 pb-1">
                        <span class="px-3 text-[10px] font-bold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Settings</span>
                    </div>

                    @can('admins.view')
                    <a href="{{ route('admin.staff') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.staff*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        <span>Staff Management</span>
                    </a>
                    @endcan

                    @can('roles.manage')
                    <a href="{{ route('admin.roles') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.roles*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                        <span>Roles & Permissions</span>
                    </a>
                    @endcan

                    @can('fees.view')
                    <a href="{{ route('admin.fees.index') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.fees.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        <span>Fee Schedules</span>
                    </a>
                    @endcan

                    @can('settlements.view')
                    <a href="{{ route('admin.settlements.index') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.settlements.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Settlements</span>
                    </a>
                    @endcan

                    @can('payouts.view')
                    <a href="{{ route('admin.payouts.index') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.payouts.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        <span>Payouts</span>
                    </a>
                    @endcan

                    @can('shipping.view')
                    <a href="{{ route('admin.shipments.index') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.shipments.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        <span>Shipments</span>
                    </a>

                    <a href="{{ route('admin.shipping.settings') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.shipping.settings') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>Shipping Settings</span>
                    </a>
                    @endcan

                    @can('audit.view')
                    <a href="{{ route('admin.activity-logs') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.activity-logs*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Activity Logs</span>
                    </a>
                    @endcan

                    <a href="{{ route('admin.profile') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.profile') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <span>Profile</span>
                    </a>
                </nav>

                <div class="border-t border-zinc-200 pt-4 dark:border-zinc-800">
                    <form method="POST" action="{{ route('admin.logout') }}">
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
                    <span class="font-bold text-lg text-zinc-900 dark:text-white">Admin Panel</span>
                    <button type="button" @click="mobileMenuOpen = false" class="p-1 rounded-md text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-white">
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="mt-4 flex-1 space-y-1 px-4 overflow-y-auto">
                    <a href="{{ route('admin.dashboard') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.dashboard') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>Dashboard</span>
                    </a>

                    @can('customers.view')
                    <a href="{{ route('admin.customers') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.customers*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                        <span>Customers</span>
                    </a>
                    @endcan

                    @can('sellers.view')
                    <a href="{{ route('admin.sellers.index') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.sellers*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                        <span>Sellers</span>
                    </a>
                    @endcan

                    @can('orders.view')
                    <a href="{{ route('admin.orders.index') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.orders.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                        </svg>
                        <span>Orders</span>
                    </a>
                    @endcan

                    @canany(['catalog.categories', 'catalog.verticals', 'catalog.brands.manage', 'catalog.products.view'])
                    <div class="pt-3 pb-1">
                        <span class="px-3 text-[10px] font-bold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Catalog</span>
                    </div>

                    @can('catalog.categories')
                    <a href="{{ route('admin.catalog.categories') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.catalog.categories*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                        </svg>
                        <span>Categories</span>
                    </a>
                    @endcan

                    @can('catalog.verticals')
                    <a href="{{ route('admin.catalog.verticals') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.catalog.verticals*') || request()->routeIs('admin.catalog.vertical-attributes*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                        </svg>
                        <span>Verticals</span>
                    </a>
                    @endcan

                    @can('catalog.brands.manage')
                    <a href="{{ route('admin.catalog.brands') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.catalog.brands*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                        </svg>
                        <span>Brands</span>
                    </a>
                    @endcan

                    @can('catalog.products.view')
                    <a href="{{ route('admin.catalog.products') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.catalog.products*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <div class="flex items-center gap-3">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                            <span>Products & QC</span>
                        </div>
                        @if ($pendingQcCount > 0)
                            <span class="inline-flex size-5 items-center justify-center rounded-full bg-amber-500 text-[10px] font-bold text-white">
                                {{ $pendingQcCount }}
                            </span>
                        @endif
                    </a>
                    @endcan
                    @endcanany

                    <div class="pt-3 pb-1">
                        <span class="px-3 text-[10px] font-bold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Settings</span>
                    </div>

                    @can('admins.view')
                    <a href="{{ route('admin.staff') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.staff*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        <span>Staff Management</span>
                    </a>
                    @endcan

                    @can('roles.manage')
                    <a href="{{ route('admin.roles') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.roles*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                        <span>Roles & Permissions</span>
                    </a>
                    @endcan

                    @can('fees.view')
                    <a href="{{ route('admin.fees.index') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.fees.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        <span>Fee Schedules</span>
                    </a>
                    @endcan

                    @can('settlements.view')
                    <a href="{{ route('admin.settlements.index') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.settlements.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Settlements</span>
                    </a>
                    @endcan

                    @can('payouts.view')
                    <a href="{{ route('admin.payouts.index') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.payouts.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        <span>Payouts</span>
                    </a>
                    @endcan

                    @can('shipping.view')
                    <a href="{{ route('admin.shipments.index') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.shipments.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        <span>Shipments</span>
                    </a>

                    <a href="{{ route('admin.shipping.settings') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.shipping.settings') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>Shipping Settings</span>
                    </a>
                    @endcan

                    @can('audit.view')
                    <a href="{{ route('admin.activity-logs') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.activity-logs*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Activity Logs</span>
                    </a>
                    @endcan

                    <a href="{{ route('admin.profile') }}"
                       @click="mobileMenuOpen = false"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.profile') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <span>Profile</span>
                    </a>
                </div>

                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    <form method="POST" action="{{ route('admin.logout') }}">
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
            <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center justify-between border-b border-zinc-200 bg-white/80 px-4 md:px-8 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-900/80">
                <div class="flex items-center gap-3">
                    <button type="button"
                            @click="mobileMenuOpen = true"
                            class="p-2 rounded-lg text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 md:hidden dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white"
                            aria-label="Open sidebar">
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <h1 class="text-base md:text-lg font-semibold text-zinc-900 dark:text-white truncate">
                        {{ $title ?? 'Admin' }}
                    </h1>
                </div>

                <div class="flex items-center gap-4">
                    @if (Auth::guard('admin')->check())
                        <div class="flex items-center gap-3">
                            <div class="hidden sm:flex flex-col text-right">
                                <span class="text-sm font-medium text-zinc-900 dark:text-white leading-tight">
                                    {{ Auth::guard('admin')->user()->name }}
                                </span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400 leading-tight">
                                    {{ Auth::guard('admin')->user()->email }}
                                </span>
                            </div>
                            <span class="flex size-9 items-center justify-center rounded-full bg-zinc-200 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-100 font-semibold text-xs ring-2 ring-zinc-300 dark:ring-zinc-600">
                                {{ Auth::guard('admin')->user()->initials() }}
                            </span>
                        </div>

                        <form method="POST" action="{{ route('admin.logout') }}" class="hidden sm:block">
                            @csrf
                            <button type="submit"
                                    class="rounded-lg px-3 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white transition">
                                Log out
                            </button>
                        </form>
                    @endif
                </div>
            </header>

            <!-- Main Body -->
            <main class="flex-1 p-4 md:p-8 max-w-7xl w-full mx-auto">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>

