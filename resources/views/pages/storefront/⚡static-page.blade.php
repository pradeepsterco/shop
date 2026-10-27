<?php

namespace App\Livewire\Storefront;

use App\Content\Enums\PageStatus;
use App\Content\Models\ContentPage;
use App\Content\Services\PageResolver;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.storefront')] class extends Component {
    public string $slug = '';

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        $page = $this->page;
        if (! $page || ! $page->isPublished()) {
            abort(404, 'Page not found.');
        }
    }

    #[Computed]
    public function page(): ?ContentPage
    {
        return ContentPage::query()
            ->where('slug', $this->slug)
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
    @if ($this->resolvedPage)
        @if ($this->resolvedPage->seoTitle)
            @section('title', $this->resolvedPage->seoTitle)
        @else
            @section('title', $this->resolvedPage->title)
        @endif

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
    @endif
</div>

