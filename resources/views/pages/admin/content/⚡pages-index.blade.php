<?php

namespace App\Livewire\Admin\Content;

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Models\ContentPage;
use App\Services\AdminActivityLogger;
use App\Support\AdminPermissions;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Pages Management')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $typeFilter = 'all';
    public string $statusFilter = 'all';

    // Create Modal state
    public bool $showCreateModal = false;
    public string $newTitle = '';
    public string $newSlug = '';
    public string $newType = 'landing';
    public string $newSeoTitle = '';
    public string $newSeoDescription = '';
    public bool $is_slug_customized = false;

    // Delete Modal state
    public ?int $deletingPageId = null;

    public function mount(): void
    {
        $this->authorize(AdminPermissions::PAGES_VIEW);
    }

    public function updatedNewTitle(string $val): void
    {
        if (! $this->is_slug_customized) {
            $this->newSlug = Str::slug($val) ?: ($this->newType === 'home' ? 'home' : '');
        }
    }

    public function updatedNewSlug(string $val): void
    {
        $clean = Str::slug($val);
        $this->newSlug = $clean;

        if (blank($val) || $clean === Str::slug($this->newTitle) || ($this->newType === 'home' && $clean === 'home')) {
            $this->is_slug_customized = false;
            if (blank($val)) {
                $this->newSlug = Str::slug($this->newTitle) ?: ($this->newType === 'home' ? 'home' : '');
            }
        } else {
            $this->is_slug_customized = true;
        }
    }

    public function updatedNewType(string $val): void
    {
        if ($val === 'home') {
            if (blank($this->newTitle)) {
                $this->newTitle = 'Home';
            }
            if (blank($this->newSlug) || ! $this->is_slug_customized) {
                $this->newSlug = 'home';
            }
        }
    }

    public function resetSlug(): void
    {
        $this->is_slug_customized = false;
        $this->newSlug = Str::slug($this->newTitle) ?: ($this->newType === 'home' ? 'home' : '');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);
        $this->resetValidation();
        $this->newTitle = '';
        $this->newSlug = '';
        $this->newType = 'landing';
        $this->newSeoTitle = '';
        $this->newSeoDescription = '';
        $this->is_slug_customized = false;
        $this->showCreateModal = true;
    }

    public function createPage(): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        if (blank($this->newSlug)) {
            $this->newSlug = Str::slug($this->newTitle) ?: ($this->newType === 'home' ? 'home' : 'page');
        }
        $validated = $this->validate([
            'newTitle' => ['required', 'string', 'max:255'],
            'newSlug' => ['required', 'string', 'max:150', 'alpha_dash', 'unique:content_pages,slug'],
            'newType' => ['required', 'string', 'in:home,landing,static'],
            'newSeoTitle' => ['nullable', 'string', 'max:255'],
            'newSeoDescription' => ['nullable', 'string', 'max:500'],
        ]);

        $page = ContentPage::create([
            'title' => trim($this->newTitle),
            'slug' => Str::slug($this->newSlug),
            'type' => PageType::from($this->newType),
            'status' => PageStatus::Draft,
            'seo_title' => $this->newSeoTitle ?: null,
            'seo_description' => $this->newSeoDescription ?: null,
        ]);

        AdminActivityLogger::log('content.page_created', $page, [
            'title' => $page->title,
            'slug' => $page->slug,
            'type' => $page->type->value,
        ]);

        $this->showCreateModal = false;
        session()->flash('status', "Page '{$page->title}' created successfully.");

        $this->redirect(route('admin.pages.builder', ['page' => $page->id]), navigate: true);
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);
        $this->deletingPageId = $id;
    }

    public function deletePage(): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        if (! $this->deletingPageId) {
            return;
        }

        $page = ContentPage::find($this->deletingPageId);
        if ($page) {
            $title = $page->title;
            $slug = $page->slug;

            AdminActivityLogger::log('content.page_deleted', $page, [
                'title' => $title,
                'slug' => $slug,
            ]);

            $page->delete();
            session()->flash('status', "Page '{$title}' deleted successfully.");
        }

        $this->deletingPageId = null;
    }

    #[Computed]
    public function pages()
    {
        return ContentPage::query()
            ->withCount('sections')
            ->when($this->search !== '', function ($q) {
                $term = trim($this->search);
                $q->where(fn ($sub) => $sub->where('title', 'like', "%{$term}%")->orWhere('slug', 'like', "%{$term}%"));
            })
            ->when($this->typeFilter !== 'all', function ($q) {
                $q->where('type', $this->typeFilter);
            })
            ->when($this->statusFilter !== 'all', function ($q) {
                $q->where('status', $this->statusFilter);
            })
            ->orderBy('id', 'desc')
            ->paginate(15);
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Content Pages</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Manage the storefront homepage, marketing landing pages, and policy/static pages.
            </p>
        </div>

        @can('pages.manage')
            <div>
                <button
                    type="button"
                    wire:click="openCreateModal"
                    class="btn-primary"
                    data-test="create-page-btn"
                >
                    <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    <span>Create New Page</span>
                </button>
            </div>
        @endcan
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    <!-- Filters Bar -->
    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="w-full md:w-80">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search title or slug..."
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-900 focus:border-zinc-500 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
            />
        </div>

        <div class="flex items-center gap-3 w-full md:w-auto overflow-x-auto">
            <!-- Type Filter -->
            <select
                wire:model.live="typeFilter"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
            >
                <option value="all">All Page Types</option>
                <option value="home">Homepage</option>
                <option value="landing">Landing Pages</option>
                <option value="static">Static / Policy</option>
            </select>

            <!-- Status Filter -->
            <select
                wire:model.live="statusFilter"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
            >
                <option value="all">All Statuses</option>
                <option value="published">Published</option>
                <option value="scheduled">Scheduled</option>
                <option value="draft">Draft</option>
            </select>
        </div>
    </div>

    <!-- Table Card -->
    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">
                <tr>
                    <th class="px-6 py-3.5">Title & Slug</th>
                    <th class="px-6 py-3.5">Type</th>
                    <th class="px-6 py-3.5">Status</th>
                    <th class="px-6 py-3.5">Sections</th>
                    <th class="px-6 py-3.5">Last Updated</th>
                    <th class="px-6 py-3.5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse ($this->pages as $page)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40 transition">
                        <td class="px-6 py-4">
                            <div class="font-bold text-zinc-900 dark:text-white">
                                {{ $page->title }}
                            </div>
                            <div class="text-xs text-zinc-400 font-mono">
                                @if ($page->isHome())
                                    <span class="text-amber-600 dark:text-amber-400 font-bold">/</span> (Root Home)
                                @else
                                    /pages/{{ $page->slug }}
                                @endif
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center rounded-md bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                {{ $page->type->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold {{ $page->status->badgeClasses() }}">
                                {{ $page->status->label() }}
                            </span>
                            @if ($page->status === \App\Content\Enums\PageStatus::Scheduled && $page->scheduled_publish_at)
                                <div class="text-[11px] text-zinc-400 mt-0.5">
                                    {{ format_datetime($page->scheduled_publish_at) }}
                                </div>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                            {{ $page->sections_count }} section(s)
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ format_datetime($page->updated_at) }}
                        </td>
                        <td class="px-6 py-4 text-right space-x-2">
                            @can('pages.manage')
                                <a
                                    href="{{ route('admin.pages.builder', ['page' => $page->id]) }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-1 text-xs font-bold text-zinc-900 hover:text-amber-600 dark:text-white dark:hover:text-amber-400"
                                >
                                    <span>Edit Builder</span>
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                                </a>

                                <button
                                    type="button"
                                    wire:click="confirmDelete({{ $page->id }})"
                                    class="text-xs text-red-600 hover:text-red-700 dark:text-red-400 cursor-pointer"
                                >
                                    Delete
                                </button>
                            @else
                                <span class="text-xs text-zinc-400">View Only</span>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-zinc-500 dark:text-zinc-400">
                            No content pages found matching your filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pt-2">
        {{ $this->pages->links() }}
    </div>

    <!-- Create Page Modal -->
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 space-y-5">
                <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Create New Page</h3>
                    <button type="button" wire:click="$set('showCreateModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-white">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <form wire:submit="createPage" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Page Title *</label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="newTitle"
                            required
                            placeholder="e.g. Festive Mega Sale"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />
                        @error('newTitle') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Slug *</label>
                            @if ($is_slug_customized)
                                <button type="button" wire:click="resetSlug" class="text-xs font-medium text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 transition cursor-pointer">
                                    Reset to auto-slug
                                </button>
                            @endif
                        </div>
                        <div class="mt-1 flex rounded-lg shadow-xs">
                            <span class="inline-flex items-center rounded-l-lg border border-r-0 border-zinc-300 bg-zinc-50 px-3 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400 font-mono">
                                {{ $newType === 'home' ? '/' : '/pages/' }}
                            </span>
                            <input
                                type="text"
                                wire:model.blur="newSlug"
                                required
                                placeholder="{{ $newType === 'home' ? 'home' : 'festive-mega-sale' }}"
                                class="block w-full rounded-r-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono text-zinc-900 focus:border-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            />
                        </div>
                        @error('newSlug') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Page Type *</label>
                        <select
                            wire:model.live="newType"
                            required
                            class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                            <option value="landing">Marketing Landing Page</option>
                            <option value="static">Static / Policy Page</option>
                            <option value="home">Storefront Homepage</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">SEO Title (Optional)</label>
                        <input
                            type="text"
                            wire:model="newSeoTitle"
                            placeholder="Browser title tag..."
                            class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">SEO Meta Description (Optional)</label>
                        <textarea
                            wire:model="newSeoDescription"
                            rows="2"
                            placeholder="Search engine summary..."
                            class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        ></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="$set('showCreateModal', false)" class="btn-secondary">
                            Cancel
                        </button>
                        <button type="submit" class="btn-primary" data-test="submit-create-page-btn">
                            Create & Open Builder
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Delete Confirmation Modal -->
    @if ($deletingPageId)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 space-y-4">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Delete Page?</h3>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Are you sure you want to delete this page and all its sections? This action cannot be undone.
                </p>
                <div class="flex items-center justify-end gap-3 pt-3">
                    <button type="button" wire:click="$set('deletingPageId', null)" class="btn-secondary">
                        Cancel
                    </button>
                    <button type="button" wire:click="deletePage" class="btn-danger">
                        Delete Page
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

