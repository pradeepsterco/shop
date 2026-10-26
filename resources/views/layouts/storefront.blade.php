<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ filled($title ?? null) ? $title.' - '.config('app.name', 'Khilauna') : config('app.name', 'Khilauna') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.theme-script')
</head>
<body class="min-h-screen bg-zinc-50 font-sans text-zinc-900 antialiased selection:bg-amber-500 selection:text-white dark:bg-zinc-950 dark:text-zinc-100">
    <!-- Storefront Header Navigation -->
    <header class="sticky top-0 z-40 border-b border-zinc-200 bg-white/90 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-900/90">
        <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
            <!-- Brand Logo -->
            <div class="flex items-center gap-6">
                <a href="{{ route('products.index') }}" class="flex items-center gap-2.5 font-bold tracking-tight text-zinc-900 dark:text-white" wire:navigate>
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500 text-white shadow-sm">
                        <x-app-logo-icon class="h-6 w-6 fill-current" />
                    </span>
                    <span class="text-xl tracking-tight">Khilauna<span class="text-amber-500">.com</span></span>
                </a>

                <nav class="hidden md:flex items-center gap-1">
                    <a href="{{ route('products.index') }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800 transition" wire:navigate>
                        All Products
                    </a>
                    <a href="{{ route('products.index', ['category' => 'toys-games']) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800 transition" wire:navigate>
                        Toys & Games
                    </a>
                    <a href="{{ route('products.index', ['category' => 'fashion']) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800 transition" wire:navigate>
                        Fashion
                    </a>
                    <a href="{{ route('products.index', ['category' => 'electronics']) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800 transition" wire:navigate>
                        Electronics
                    </a>
                </nav>
            </div>

            <!-- Cart & Customer Account / Auth Links -->
            <div class="flex items-center gap-3">
                <livewire:header-cart-badge />

                @auth
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3.5 py-1.5 text-sm font-medium text-zinc-800 shadow-xs hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 transition" wire:navigate>
                        <svg class="h-4 w-4 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                        <span>Dashboard</span>
                    </a>
                @else
                    <a href="{{ route('login') }}" class="text-sm font-medium text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white transition" wire:navigate>
                        Log in
                    </a>
                    <a href="{{ route('register') }}" class="inline-flex items-center rounded-lg bg-zinc-900 px-3.5 py-1.5 text-sm font-medium text-white shadow-xs hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition" wire:navigate>
                        Register
                    </a>
                @endauth
            </div>
        </div>
    </header>

    <!-- Main Content Container -->
    <main class="min-h-[calc(100vh-16rem)]">
        {{ $slot }}
    </main>

    <!-- Storefront Footer -->
    <footer class="border-t border-zinc-200 bg-white py-12 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col items-center justify-between gap-6 sm:flex-row">
                <div class="flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-500 text-white">
                        <x-app-logo-icon class="h-4 w-4 fill-current" />
                    </span>
                    <span class="font-bold tracking-tight text-zinc-900 dark:text-white">Khilauna Store</span>
                </div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    &copy; {{ date('Y') }} Khilauna.com. All rights reserved. Premium multi-seller toys & lifestyle marketplace.
                </p>
                <div class="flex items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400">
                    <a href="{{ route('seller.apply') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200 transition">Sell on Khilauna</a>
                    <span>&middot;</span>
                    <a href="{{ route('products.index') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200 transition" wire:navigate>All Categories</a>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>

