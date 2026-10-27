<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ filled($title ?? null) ? $title.' - '.brand_name('admin') : 'Admin Login - '.brand_name('admin') }}</title>

    <x-brand.favicon />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.theme-script')
</head>
<body class="min-h-screen bg-zinc-100 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <div class="flex min-h-screen flex-col items-center justify-center p-6">
        <div class="mb-6 flex justify-center">
            <x-brand.logo area="admin" size="lg" />
        </div>
        <div class="w-full max-w-md">
            {{ $slot }}
        </div>
    </div>
</body>
</html>

