<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-100 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        @php
            // Support requests with replies the customer has not opened
            $supportUnread = auth()->check()
                ? app(\App\Helpdesk\Services\HelpdeskService::class)->unreadCountForRequester((int) auth()->id(), \App\Helpdesk\Enums\RequesterType::Customer)
                : 0;
        @endphp
        <div x-data="{ mobileMenuOpen: false }" class="flex min-h-screen">
            <!-- Desktop Sidebar -->
            <aside data-test="customer-sidebar" class="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0 border-r border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 z-30">
                <div class="flex h-16 shrink-0 items-center gap-3 px-6 border-b border-zinc-200 dark:border-zinc-800">
                    <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2 font-bold tracking-tight text-zinc-900 dark:text-white">
                        <x-brand.logo area="customer" />
                    </a>
                </div>

                <div class="flex flex-1 flex-col justify-between overflow-y-auto px-4 py-6">
                    <nav class="space-y-1">
                        <a href="{{ route('dashboard') }}"
                           wire:navigate
                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                            </svg>
                            <span>{{ __('Dashboard') }}</span>
                        </a>

                        <a href="{{ route('orders.index') }}"
                           wire:navigate
                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('orders.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                            </svg>
                            <span>{{ __('My Orders') }}</span>
                        </a>

                        <a href="{{ route('addresses.index') }}"
                           wire:navigate
                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('addresses.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>
                            </svg>
                            <span>{{ __('Addresses') }}</span>
                        </a>

                        <a href="{{ route('support.index') }}"
                           wire:navigate
                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('support.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/>
                            </svg>
                            <span>{{ __('Help & Support') }}</span>
                            <x-support.unread-badge :count="$supportUnread" />
                        </a>

                        <div class="pt-3 pb-1">
                            <span class="px-3 text-[10px] font-bold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('Settings') }}</span>
                        </div>

                        <a href="{{ route('profile.edit') }}"
                           wire:navigate
                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('profile.edit') || request()->routeIs('security.edit') || request()->routeIs('appearance.edit') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <span>{{ __('Account Settings') }}</span>
                        </a>
                    </nav>

                    <div class="border-t border-zinc-200 p-4 dark:border-zinc-800 space-y-2">
                        <a href="{{ route('products.index') }}"
                           wire:navigate
                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white transition">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                            </svg>
                            <span>{{ __('Browse Store') }}</span>
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"
                                    data-test="logout-button"
                                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-950/30 dark:hover:text-red-300 cursor-pointer">
                                <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                </svg>
                                <span>{{ __('Log out') }}</span>
                            </button>
                        </form>
                    </div>
                </div>
            </aside>

            <!-- Mobile Drawer Menu -->
            <div x-show="mobileMenuOpen"
                 data-test="customer-mobile-menu"
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
                     class="fixed inset-0 bg-zinc-900/80 backdrop-blur-xs"></div>

                <div x-show="mobileMenuOpen"
                     x-transition:enter="transition ease-in-out duration-200 transform"
                     x-transition:enter-start="-translate-x-full"
                     x-transition:enter-end="translate-x-0"
                     x-transition:leave="transition ease-in-out duration-200 transform"
                     x-transition:leave-start="translate-x-0"
                     x-transition:leave-end="-translate-x-full"
                     class="relative flex w-full max-w-xs flex-1 flex-col bg-white pt-5 pb-4 dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-800">
                    <div class="flex items-center justify-between px-6 pb-4 border-b border-zinc-200 dark:border-zinc-800">
                        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2 font-bold tracking-tight text-zinc-900 dark:text-white">
                            <x-brand.logo area="customer" />
                        </a>
                        <button type="button" @click="mobileMenuOpen = false" class="p-1 rounded-md text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-white cursor-pointer">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="mt-4 flex-1 space-y-1 px-4 overflow-y-auto">
                        <a href="{{ route('dashboard') }}"
                           @click="mobileMenuOpen = false"
                           wire:navigate
                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                            </svg>
                            <span>{{ __('Dashboard') }}</span>
                        </a>

                        <a href="{{ route('orders.index') }}"
                           @click="mobileMenuOpen = false"
                           wire:navigate
                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('orders.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                            </svg>
                            <span>{{ __('My Orders') }}</span>
                        </a>

                        <a href="{{ route('addresses.index') }}"
                           @click="mobileMenuOpen = false"
                           wire:navigate
                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('addresses.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>
                            </svg>
                            <span>{{ __('Addresses') }}</span>
                        </a>

                        <a href="{{ route('support.index') }}"
                           @click="mobileMenuOpen = false"
                           wire:navigate
                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('support.*') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/>
                            </svg>
                            <span>{{ __('Help & Support') }}</span>
                            <x-support.unread-badge :count="$supportUnread" />
                        </a>

                        <div class="pt-3 pb-1">
                            <span class="px-3 text-[10px] font-bold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('Settings') }}</span>
                        </div>

                        <a href="{{ route('profile.edit') }}"
                           @click="mobileMenuOpen = false"
                           wire:navigate
                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('profile.edit') || request()->routeIs('security.edit') || request()->routeIs('appearance.edit') ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white font-semibold' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' }}">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <span>{{ __('Account Settings') }}</span>
                        </a>
                    </div>

                    <div class="border-t border-zinc-200 p-4 dark:border-zinc-800 space-y-2">
                        <a href="{{ route('products.index') }}"
                           @click="mobileMenuOpen = false"
                           wire:navigate
                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white transition">
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                            </svg>
                            <span>{{ __('Browse Store') }}</span>
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"
                                    data-test="logout-button"
                                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-950/30 dark:hover:text-red-300 cursor-pointer">
                                <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                </svg>
                                <span>{{ __('Log out') }}</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="flex flex-1 flex-col md:pl-64 min-w-0">
                <!-- Top Bar -->
                <x-dashboard.topbar :title="$title ?? __('Dashboard')" role="user" guard="web" />

                <!-- Main Body -->
                <main class="flex-1 p-4 md:p-8 max-w-7xl w-full mx-auto">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
