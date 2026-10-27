@props([
    'guard' => 'web',
    'role' => 'user',
])

@php
    $user = $guard === 'admin' ? Auth::guard('admin')->user() : auth()->user();
    $logoutRoute = $guard === 'admin' ? route('admin.logout') : route('logout');
@endphp

@if ($user)
    <div x-data="{ open: false }" class="relative">
        <button type="button"
                @click="open = !open"
                class="flex items-center gap-2.5 rounded-lg p-1 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition cursor-pointer"
                aria-haspopup="true"
                :aria-expanded="open.toString()">
            <div class="hidden sm:flex flex-col text-right leading-tight">
                <span class="text-xs font-semibold text-zinc-900 dark:text-white max-w-[130px] truncate">
                    {{ $user->name }}
                </span>
                <span class="text-[11px] text-zinc-500 dark:text-zinc-400 max-w-[130px] truncate">
                    {{ $user->email }}
                </span>
            </div>

            @if ($role === 'seller')
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200 font-bold text-xs ring-2 ring-emerald-300 dark:ring-emerald-700">
                    {{ method_exists($user, 'initials') ? $user->initials() : substr($user->name, 0, 2) }}
                </span>
            @elseif ($role === 'admin')
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-900 text-white dark:bg-white dark:text-zinc-900 font-bold text-xs ring-2 ring-zinc-300 dark:ring-zinc-600">
                    {{ method_exists($user, 'initials') ? $user->initials() : substr($user->name, 0, 2) }}
                </span>
            @else
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-100 font-bold text-xs ring-2 ring-zinc-300 dark:ring-zinc-600">
                    {{ method_exists($user, 'initials') ? $user->initials() : substr($user->name, 0, 2) }}
                </span>
            @endif

            <svg class="size-3.5 text-zinc-400 transition-transform duration-150"
                 :class="{ 'rotate-180': open }"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <div x-show="open"
             data-test="account-menu-dropdown"
             @click.outside="open = false"
             x-cloak
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="transform opacity-0 scale-95"
             x-transition:enter-end="transform opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="transform opacity-100 scale-100"
             x-transition:leave-end="transform opacity-0 scale-95"
             class="absolute right-0 z-50 mt-2 w-56 origin-top-right rounded-xl border border-zinc-200 bg-white p-1.5 shadow-lg dark:border-zinc-800 dark:bg-zinc-900 text-xs">
            {{-- User Info Header --}}
            <div class="px-3 py-2 border-b border-zinc-100 dark:border-zinc-800">
                <p class="font-semibold text-zinc-900 dark:text-white truncate">{{ $user->name }}</p>
                <p class="text-[11px] text-zinc-500 dark:text-zinc-400 truncate">{{ $user->email }}</p>
            </div>

            {{-- Links --}}
            @if ($role === 'admin')
                <div class="py-1">
                    <a href="{{ route('admin.profile') }}"
                       wire:navigate
                       @click="open = false"
                       class="flex items-center gap-2.5 px-3 py-2 text-zinc-700 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white rounded-lg transition">
                        <svg class="size-4 shrink-0 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <span>Admin Profile</span>
                    </a>
                    <a href="{{ route('admin.dashboard') }}"
                       wire:navigate
                       @click="open = false"
                       class="flex items-center gap-2.5 px-3 py-2 text-zinc-700 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white rounded-lg transition">
                        <svg class="size-4 shrink-0 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>Dashboard</span>
                    </a>
                </div>
            @endif

            <div class="{{ $role === 'admin' ? 'border-t border-zinc-100 dark:border-zinc-800 pt-1' : 'pt-1' }}">
                <form method="POST" action="{{ $logoutRoute }}" class="w-full">
                    @csrf
                    <button type="submit"
                            data-test="logout-button"
                            class="flex w-full items-center gap-2.5 px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-950/40 dark:hover:text-red-300 rounded-lg transition cursor-pointer">
                        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                        <span>Log out</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
@endif

