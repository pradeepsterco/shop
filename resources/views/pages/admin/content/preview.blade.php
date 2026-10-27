<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>[Preview] {{ $resolvedPage?->title ?? $page->title }} - {{ brand_name() }}</title>

    <x-brand.favicon />
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.theme-script')
    @livewireStyles
    @livewireScripts

    <style>
        /* When inside the preview iframe, hide toolbar and outer shell, show the storefront content */
        html.is-preview-frame #preview-header { display: none !important; }
        html.is-preview-frame #preview-parent-shell { display: none !important; }

        /* Ensure iframe background and text colors match current light/dark theme perfectly */
        html.is-preview-frame body {
            padding: 0 !important;
            margin: 0 !important;
        }
        html.is-preview-frame.dark body,
        html.is-preview-frame.dark {
            background-color: #09090b !important;
            color: #f4f4f5 !important;
        }
        html.is-preview-frame:not(.dark) body,
        html.is-preview-frame:not(.dark) {
            background-color: #ffffff !important;
            color: #18181b !important;
        }

        /* When in top window (parent), hide raw content so it only renders inside the responsive iframe */
        html:not(.is-preview-frame) #preview-raw-content { display: none !important; }
    </style>
    <script>
        if (window.self !== window.top) {
            document.documentElement.classList.add('is-preview-frame');
        }
    </script>
</head>
<body class="min-h-screen bg-zinc-200 dark:bg-zinc-950 font-sans text-zinc-900 dark:text-zinc-100 flex flex-col">

    {{-- TOP WINDOW: Toolbar + Resizable responsive iframe container --}}
    <div
        id="preview-parent-shell"
        x-data="{
            viewport: 'desktop',
            setMode(mode) {
                this.viewport = mode;
                window.__setPreviewViewport && window.__setPreviewViewport(mode);
            }
        }"
        class="flex flex-col min-h-screen"
    >
        <!-- Preview Header Toolbar -->
        <header id="preview-header" class="sticky top-0 z-50 flex items-center justify-between border-b border-zinc-300 bg-white/95 px-4 py-2.5 shadow-sm backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95">
            <div class="flex items-center gap-3">
                <a
                    href="{{ route('admin.pages.builder', ['page' => $page->id]) }}"
                    class="inline-flex items-center gap-1.5 text-xs font-bold text-zinc-700 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
                    <span>Back to Builder</span>
                </a>
                <span class="text-zinc-300 dark:text-zinc-700">|</span>
                <span class="text-xs font-bold text-zinc-800 dark:text-zinc-200 truncate max-w-xs">
                    {{ $page->title }} <span class="text-zinc-400 font-normal">({{ $page->status->label() }} Draft)</span>
                </span>
            </div>

            <!-- Viewport Switcher — client-side Alpine + JS, keeps signed URL intact -->
            <div class="flex items-center gap-1 rounded-lg border border-zinc-200 bg-zinc-100 p-1 dark:border-zinc-800 dark:bg-zinc-800">
                <button
                    type="button"
                    id="btn-viewport-desktop"
                    @click="setMode('desktop')"
                    onclick="window.__setPreviewViewport && window.__setPreviewViewport('desktop')"
                    class="rounded px-2.5 py-1 text-xs font-semibold transition bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white cursor-pointer"
                    title="Desktop View"
                >
                    Desktop
                </button>
                <button
                    type="button"
                    id="btn-viewport-mobile"
                    @click="setMode('mobile')"
                    onclick="window.__setPreviewViewport && window.__setPreviewViewport('mobile')"
                    class="rounded px-2.5 py-1 text-xs font-semibold transition text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 cursor-pointer"
                    title="Mobile View"
                >
                    Mobile
                </button>
            </div>

            <!-- Right side: Theme Switcher & Preview Badge -->
            <div class="flex items-center gap-3">
                <x-theme-switcher />
                <span class="rounded bg-amber-100 text-amber-800 text-[10px] font-bold px-2 py-0.5 dark:bg-amber-950 dark:text-amber-300">
                    PREVIEW MODE
                </span>
            </div>
        </header>

        <!-- Preview Canvas / Frame Holder -->
        <main class="flex-1 flex justify-center overflow-y-auto bg-zinc-200 dark:bg-zinc-950 p-2 sm:p-4">
            <div
                id="preview-viewport-box"
                class="transition-all duration-300 w-full h-[calc(100vh-80px)] max-w-7xl bg-white dark:bg-zinc-950 rounded-2xl shadow-sm overflow-hidden"
            >
                <iframe
                    id="preview-iframe"
                    title="Page Preview"
                    class="w-full h-full border-0 bg-white dark:bg-zinc-950"
                ></iframe>
            </div>
        </main>
    </div>

    {{-- IFRAME CONTENT: Pure storefront sections with native CSS breakpoints --}}
    <div id="preview-raw-content" class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
        @if ($resolvedPage && count($resolvedPage->sections) > 0)
            <div class="space-y-10 sm:space-y-14">
                @foreach ($resolvedPage->sections as $section)
                    @php
                        $visClass = match ($section->visibility) {
                            'desktop' => 'hidden md:block',
                            'mobile' => 'block md:hidden',
                            default => 'block',
                        };
                    @endphp
                    <section class="{{ $visClass }}" data-section-type="{{ $section->type }}">
                        <x-dynamic-component :component="'content.sections.'.str_replace('_', '-', $section->type)" :section="$section" />
                    </section>
                @endforeach
            </div>
        @else
            <div class="p-16 text-center text-zinc-400">
                No sections added to this page yet.
            </div>
        @endif
    </div>

    <script>
        // Only load the iframe if we are in the top-level window (prevents recursive iframes)
        if (window.self === window.top) {
            const frame = document.getElementById('preview-iframe');
            if (frame) {
                frame.src = window.location.href;
            }

            // Client-side viewport toggle function (used by both Alpine and vanilla onclick)
            window.__setPreviewViewport = function(mode) {
                const frameBox = document.getElementById('preview-viewport-box');
                const btnDesk = document.getElementById('btn-viewport-desktop');
                const btnMob = document.getElementById('btn-viewport-mobile');
                if (!frameBox || !btnDesk || !btnMob) return;

                if (mode === 'mobile') {
                    frameBox.className = 'transition-all duration-300 w-[390px] h-[844px] my-6 mx-auto rounded-[40px] border-8 border-zinc-800 shadow-2xl overflow-hidden bg-white dark:bg-zinc-950 shrink-0';
                    btnDesk.className = 'rounded px-2.5 py-1 text-xs font-semibold transition text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 cursor-pointer';
                    btnMob.className = 'rounded px-2.5 py-1 text-xs font-semibold transition bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white cursor-pointer';
                } else {
                    frameBox.className = 'transition-all duration-300 w-full h-[calc(100vh-80px)] max-w-7xl bg-white dark:bg-zinc-950 rounded-2xl shadow-sm overflow-hidden';
                    btnDesk.className = 'rounded px-2.5 py-1 text-xs font-semibold transition bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white cursor-pointer';
                    btnMob.className = 'rounded px-2.5 py-1 text-xs font-semibold transition text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 cursor-pointer';
                }
            };

            // Broadcast theme change to iframe whenever appearance changes
            const broadcastTheme = () => {
                const previewFrame = document.getElementById('preview-iframe');
                if (previewFrame && previewFrame.contentWindow) {
                    previewFrame.contentWindow.postMessage({ type: 'flux-theme-sync' }, '*');
                }
            };

            window.addEventListener('storage', (e) => {
                if (e.key === 'flux.appearance') broadcastTheme();
            });

            const themeObserver = new MutationObserver(broadcastTheme);
            themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        } else {
            // Inside iframe: listen for theme changes from parent window
            window.addEventListener('message', (e) => {
                if (e.data && e.data.type === 'flux-theme-sync') {
                    const theme = (window.Flux && window.Flux.appearance) || localStorage.getItem('flux.appearance') || 'system';
                    const isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', isDark);
                }
            });
        }
    </script>
</body>
</html>
