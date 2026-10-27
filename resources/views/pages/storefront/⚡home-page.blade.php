<?php

namespace App\Livewire\Storefront;

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Models\ContentPage;
use App\Content\Services\PageResolver;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.storefront')] #[Title('Home')] class extends Component {
    #[Computed]
    public function page()
    {
        return ContentPage::query()
            ->where('type', PageType::Home)
            ->where('status', PageStatus::Published)
            ->first();
    }

    #[Computed]
    public function resolvedPage()
    {
        $page = $this->page;
        if (! $page) {
            return null;
        }

        return app(PageResolver::class)->resolve($page, 'storefront');
    }
}; ?>

<div>
    @if ($this->resolvedPage && count($this->resolvedPage->sections) > 0)
        <div class="space-y-10 sm:space-y-14 py-6 sm:py-8">
            @foreach ($this->resolvedPage->sections as $section)
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
        <!-- Fallback Default Homepage when no content page has been published yet -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 text-center space-y-6">
            <span class="rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 px-4 py-1.5 text-xs font-bold uppercase tracking-wider">
                Welcome to {{ brand_name() }}
            </span>
            <h1 class="text-3xl sm:text-5xl font-black tracking-tight text-zinc-900 dark:text-white max-w-2xl mx-auto leading-tight">
                Discover Verified Sellers & Premium Marketplace Deals
            </h1>
            <p class="text-sm sm:text-base text-zinc-600 dark:text-zinc-400 max-w-xl mx-auto">
                Explore thousands of curated products from trusted independent sellers with lightning-fast delivery and verified buyer protection.
            </p>
            <div class="pt-4 flex items-center justify-center gap-4">
                <a href="{{ route('products.index') }}" wire:navigate class="rounded-xl bg-zinc-900 px-6 py-3 text-sm font-bold text-white shadow-xs hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition">
                    Browse All Products
                </a>
                <a href="{{ route('seller.apply') }}" wire:navigate class="rounded-xl border border-zinc-300 bg-white px-6 py-3 text-sm font-bold text-zinc-700 shadow-xs hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 transition">
                    Sell on {{ brand_name() }}
                </a>
            </div>
        </div>
    @endif
</div>

