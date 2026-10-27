<?php

namespace App\Livewire\Admin\Content;

use App\Catalog\Contracts\CatalogReadInterface;
use App\Content\Enums\LinkType;
use App\Content\Enums\PageStatus;
use App\Content\Enums\SectionVisibility;
use App\Content\Models\ContentMedia;
use App\Content\Models\ContentPage;
use App\Content\Models\ContentPageVersion;
use App\Content\Models\ContentSection;
use App\Content\Models\ContentSectionItem;
use App\Content\Sections\SectionTypeRegistry;
use App\Content\Services\MediaUploadService;
use App\Content\Services\PagePublishService;
use App\Services\AdminActivityLogger;
use App\Support\AdminPermissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Page Builder')] class extends Component {
    use WithFileUploads;

    public int $pageId;

    // Active editing section
    public ?int $editingSectionId = null;
    public string $editingSectionType = '';
    public array $editingSettings = [];
    public string $editingVisibility = 'all';
    public ?string $editingStartsAt = null;
    public ?string $editingEndsAt = null;

    // Section Library modal
    public bool $showLibraryModal = false;

    // Media Picker / Upload modal
    public bool $showMediaModal = false;
    public ?int $mediaTargetItemId = null;
    public string $mediaTargetSlot = 'desktop'; // 'desktop' or 'mobile'
    public $uploadedImage = null;
    public string $mediaAltText = '';
    public string $mediaModalTab = 'browse'; // 'browse' or 'upload'
    public ?string $mediaDeleteError = null;

    // Link Picker modal
    public bool $showLinkModal = false;
    public ?int $linkTargetItemId = null;
    public string $linkSearchType = 'category';
    public string $linkSearchQuery = '';
    public array $linkSearchResults = [];
    public string $linkCustomUrl = '';

    // Schedule modal
    public bool $showScheduleModal = false;
    public string $scheduleDate = '';

    // Version History modal
    public bool $showVersionModal = false;

    public function mount(int|ContentPage $page): void
    {
        $this->authorize(AdminPermissions::PAGES_VIEW);
        $this->pageId = $page instanceof ContentPage ? (int) $page->id : (int) $page;

        $p = $this->page;
        if (! $p) {
            abort(404, 'Page not found.');
        }
    }

    #[Computed]
    public function page(): ?ContentPage
    {
        return ContentPage::with(['sections.items.desktopMedia', 'sections.items.mobileMedia', 'versions.publisher'])->find($this->pageId);
    }

    public function openLibrary(): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);
        $this->showLibraryModal = true;
    }

    public function addSection(string $type, SectionTypeRegistry $registry): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        if (! $registry->has($type)) {
            return;
        }

        $definition = $registry->get($type);
        $nextPos = (ContentSection::where('page_id', $this->pageId)->max('position') ?? -1) + 1;

        $section = ContentSection::create([
            'page_id' => $this->pageId,
            'type' => $type,
            'schema_version' => $definition->schemaVersion(),
            'position' => $nextPos,
            'visibility' => SectionVisibility::All,
            'settings' => $definition->defaultSettings(),
        ]);

        // Add 1 default item if supported
        if ($definition->supportsItems()) {
            ContentSectionItem::create([
                'section_id' => $section->id,
                'position' => 0,
                'settings' => $definition->defaultItemSettings(),
            ]);
        }

        $this->showLibraryModal = false;
        $this->editSection($section->id);
    }

    public function moveSectionUp(int $sectionId): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        $section = ContentSection::where('page_id', $this->pageId)->find($sectionId);
        if (! $section || $section->position === 0) {
            return;
        }

        $prevSection = ContentSection::where('page_id', $this->pageId)
            ->where('position', '<', $section->position)
            ->orderByDesc('position')
            ->first();

        if ($prevSection) {
            $prevPos = $prevSection->position;
            $prevSection->update(['position' => $section->position]);
            $section->update(['position' => $prevPos]);
        }
    }

    public function moveSectionDown(int $sectionId): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        $section = ContentSection::where('page_id', $this->pageId)->find($sectionId);
        if (! $section) {
            return;
        }

        $nextSection = ContentSection::where('page_id', $this->pageId)
            ->where('position', '>', $section->position)
            ->orderBy('position')
            ->first();

        if ($nextSection) {
            $nextPos = $nextSection->position;
            $nextSection->update(['position' => $section->position]);
            $section->update(['position' => $nextPos]);
        }
    }

    public function toggleHideSection(int $sectionId): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        $section = ContentSection::where('page_id', $this->pageId)->find($sectionId);
        if ($section) {
            $section->update(['is_hidden' => ! $section->is_hidden]);
        }
    }

    public function duplicateSection(int $sectionId): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        $section = ContentSection::with('items')->where('page_id', $this->pageId)->find($sectionId);
        if (! $section) {
            return;
        }

        $nextPos = (ContentSection::where('page_id', $this->pageId)->max('position') ?? -1) + 1;

        $newSection = ContentSection::create([
            'page_id' => $this->pageId,
            'type' => $section->type,
            'schema_version' => $section->schema_version,
            'position' => $nextPos,
            'visibility' => $section->visibility,
            'starts_at' => $section->starts_at,
            'ends_at' => $section->ends_at,
            'is_hidden' => $section->is_hidden,
            'settings' => $section->settings,
        ]);

        foreach ($section->items as $item) {
            ContentSectionItem::create([
                'section_id' => $newSection->id,
                'position' => $item->position,
                'desktop_media_id' => $item->desktop_media_id,
                'mobile_media_id' => $item->mobile_media_id,
                'link_type' => $item->link_type,
                'link_id' => $item->link_id,
                'link_url' => $item->link_url,
                'settings' => $item->settings,
            ]);
        }

        session()->flash('builder-status', 'Section duplicated.');
    }

    public function deleteSection(int $sectionId): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        ContentSection::where('page_id', $this->pageId)->where('id', $sectionId)->delete();

        if ($this->editingSectionId === $sectionId) {
            $this->editingSectionId = null;
        }

        session()->flash('builder-status', 'Section deleted.');
    }

    public function editSection(int $sectionId): void
    {
        $this->authorize(AdminPermissions::PAGES_VIEW);

        $section = ContentSection::where('page_id', $this->pageId)->find($sectionId);
        if (! $section) {
            return;
        }

        $this->editingSectionId = $section->id;
        $this->editingSectionType = $section->type;
        $this->editingSettings = $section->settings ?? [];
        $this->editingVisibility = $section->visibility instanceof \BackedEnum ? $section->visibility->value : (string) $section->visibility;
        $this->editingStartsAt = $section->starts_at?->format('Y-m-d\TH:i');
        $this->editingEndsAt = $section->ends_at?->format('Y-m-d\TH:i');
    }

    public function saveEditingSection(SectionTypeRegistry $registry): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        if (! $this->editingSectionId) {
            return;
        }

        $section = ContentSection::where('page_id', $this->pageId)->find($this->editingSectionId);
        if (! $section) {
            return;
        }

        // Validate settings against section type
        $validatedSettings = $registry->validateSettings($section->type, $this->editingSettings);

        $section->update([
            'settings' => $validatedSettings,
            'visibility' => SectionVisibility::from($this->editingVisibility),
            'starts_at' => $this->editingStartsAt ? Carbon::parse($this->editingStartsAt) : null,
            'ends_at' => $this->editingEndsAt ? Carbon::parse($this->editingEndsAt) : null,
        ]);

        session()->flash('section-saved', 'Section settings updated successfully.');
    }

    public function addItemToEditingSection(SectionTypeRegistry $registry): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        if (! $this->editingSectionId) {
            return;
        }

        $section = ContentSection::where('page_id', $this->pageId)->find($this->editingSectionId);
        if (! $section) {
            return;
        }

        $definition = $registry->get($section->type);
        if (! $definition->supportsItems()) {
            return;
        }

        $currentCount = ContentSectionItem::where('section_id', $section->id)->count();
        if ($definition->maxItems() !== null && $currentCount >= $definition->maxItems()) {
            session()->flash('section-saved', "Maximum {$definition->maxItems()} items allowed for this section.");

            return;
        }

        ContentSectionItem::create([
            'section_id' => $section->id,
            'position' => $currentCount,
            'settings' => $definition->defaultItemSettings(),
        ]);
    }

    public function updateItemSetting(int $itemId, string $key, mixed $val): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        $item = ContentSectionItem::find($itemId);
        if ($item && $item->section?->page_id === $this->pageId) {
            $settings = $item->settings ?? [];
            $settings[$key] = $val;
            $item->update(['settings' => $settings]);
        }
    }

    public function deleteItem(int $itemId): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        $item = ContentSectionItem::find($itemId);
        if ($item && $item->section?->page_id === $this->pageId) {
            $item->delete();
        }
    }

    // Media Picker / Upload
    public function openMediaPicker(int $itemId, string $slot = 'desktop'): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        $this->mediaTargetItemId = $itemId;
        $this->mediaTargetSlot = $slot;
        $this->uploadedImage = null;
        $this->mediaAltText = '';
        $this->mediaModalTab = 'browse';
        $this->mediaDeleteError = null;
        $this->showMediaModal = true;
    }

    public function selectMedia(int $mediaId): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        if (! $this->mediaTargetItemId) {
            return;
        }

        $item = ContentSectionItem::find($this->mediaTargetItemId);
        if ($item && $item->section?->page_id === $this->pageId) {
            if ($this->mediaTargetSlot === 'mobile') {
                $item->update(['mobile_media_id' => $mediaId]);
            } else {
                $item->update(['desktop_media_id' => $mediaId]);
            }
        }

        $this->showMediaModal = false;
    }

    public function removeMediaFromSlot(int $itemId, string $slot): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        $item = ContentSectionItem::find($itemId);
        if ($item && $item->section?->page_id === $this->pageId) {
            if ($slot === 'mobile') {
                $item->update(['mobile_media_id' => null]);
            } else {
                $item->update(['desktop_media_id' => null]);
            }
        }
    }

    public function uploadNewMedia(MediaUploadService $uploadService): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        $this->validate([
            'uploadedImage' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'mediaAltText' => ['required', 'string', 'max:255'],
        ]);

        $media = $uploadService->upload($this->uploadedImage, $this->mediaAltText);

        $this->selectMedia($media->id);
        $this->uploadedImage = null;
        $this->mediaAltText = '';
    }

    public function deleteMediaAsset(int $mediaId, MediaUploadService $uploadService): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        $media = ContentMedia::find($mediaId);
        if (! $media) {
            return;
        }

        try {
            $uploadService->delete($media);
            $this->mediaDeleteError = null;
        } catch (DomainException $e) {
            $this->mediaDeleteError = $e->getMessage();
        }
    }

    // Link Picker
    public function openLinkPicker(int $itemId): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        $this->linkTargetItemId = $itemId;
        $this->linkSearchType = 'category';
        $this->linkSearchQuery = '';
        $this->linkSearchResults = [];
        $this->linkCustomUrl = '';

        $item = ContentSectionItem::find($itemId);
        if ($item && $item->link_type === LinkType::Url) {
            $this->linkSearchType = 'url';
            $this->linkCustomUrl = $item->link_url ?? '';
        }

        $this->showLinkModal = true;
    }

    public function searchLinkTargets(CatalogReadInterface $catalogRead): void
    {
        $q = trim($this->linkSearchQuery);
        if ($q === '') {
            $this->linkSearchResults = [];

            return;
        }

        $this->linkSearchResults = match ($this->linkSearchType) {
            'category' => $catalogRead->searchCategories($q, 8),
            'brand' => $catalogRead->searchBrands($q, 8),
            'product' => $catalogRead->searchProducts($q, 8),
            'page' => ContentPage::where('title', 'like', "%{$q}%")->orWhere('slug', 'like', "%{$q}%")->limit(8)->get(['id', 'title', 'slug'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->title, 'slug' => $p->slug])->all(),
            default => [],
        };
    }

    public function applyTargetLink(string $type, string $id, ?string $url = null): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        if (! $this->linkTargetItemId) {
            return;
        }

        $item = ContentSectionItem::find($this->linkTargetItemId);
        if ($item && $item->section?->page_id === $this->pageId) {
            $item->update([
                'link_type' => LinkType::from($type),
                'link_id' => $id,
                'link_url' => $url,
            ]);
        }

        $this->showLinkModal = false;
    }

    public function applyCustomUrl(): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        $this->validate([
            'linkCustomUrl' => ['required', 'string', 'max:1000'],
        ]);

        $this->applyTargetLink('url', '', $this->linkCustomUrl);
    }

    public function removeLink(int $itemId): void
    {
        $this->authorize(AdminPermissions::PAGES_MANAGE);

        $item = ContentSectionItem::find($itemId);
        if ($item && $item->section?->page_id === $this->pageId) {
            $item->update([
                'link_type' => null,
                'link_id' => null,
                'link_url' => null,
            ]);
        }
    }

    // Publish & Rollback
    public function publishNow(PagePublishService $publishService): void
    {
        $this->authorize(AdminPermissions::PAGES_PUBLISH);

        $page = $this->page;
        if (! $page) {
            return;
        }

        $version = $publishService->publish($page, auth('admin')->id());
        session()->flash('builder-status', "Page published successfully as Version #{$version->version_number}.");
    }

    public function schedulePublish(PagePublishService $publishService): void
    {
        $this->authorize(AdminPermissions::PAGES_PUBLISH);

        $this->validate([
            'scheduleDate' => ['required', 'date', 'after:now'],
        ]);

        $page = $this->page;
        if ($page) {
            $publishService->schedule($page, Carbon::parse($this->scheduleDate), auth('admin')->id());
            $this->showScheduleModal = false;
            session()->flash('builder-status', 'Page publication scheduled for '.format_datetime($this->scheduleDate).'.');
        }
    }

    public function rollbackToVersion(int $versionId, PagePublishService $publishService): void
    {
        $this->authorize(AdminPermissions::PAGES_PUBLISH);

        $version = ContentPageVersion::find($versionId);
        $page = $this->page;

        if ($version && $page) {
            $publishService->rollback($page, $version, auth('admin')->id());
            $this->showVersionModal = false;
            $this->editingSectionId = null;
            session()->flash('builder-status', "Restored Version #{$version->version_number} as draft.");
        }
    }

    public function getPreviewUrlProperty(): string
    {
        return URL::temporarySignedRoute(
            'admin.pages.preview',
            now()->addMinutes(60),
            ['page' => $this->pageId]
        );
    }

    #[Computed]
    public function availableMedia()
    {
        return ContentMedia::latest('id')->paginate(18);
    }
}; ?>

<div class="space-y-6">
    <!-- Top Action Toolbar -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-zinc-200 pb-4 dark:border-zinc-800">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.pages.index') }}" wire:navigate class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
            </a>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                        {{ $this->page->title }}
                    </h1>
                    <span class="rounded-md px-2 py-0.5 text-xs font-semibold {{ $this->page->status->badgeClasses() }}">
                        {{ $this->page->status->label() }}
                    </span>
                    @if ($this->page->isHome())
                        <span class="rounded-md bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 px-2 py-0.5 text-xs font-bold">
                            Root Home
                        </span>
                    @endif
                </div>
                <div class="text-xs text-zinc-400 font-mono mt-0.5">
                    {{ $this->page->isHome() ? '/' : '/pages/'.$this->page->slug }}
                </div>
            </div>
        </div>

        <!-- Buttons -->
        <div class="flex items-center gap-2 overflow-x-auto">
            <a
                href="{{ $this->previewUrl }}"
                target="_blank"
                class="btn-secondary"
                title="Open signed preview in new tab"
            >
                <svg class="size-4 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span>Preview</span>
            </a>

            @can('pages.publish')
                <button type="button" wire:click="$set('showVersionModal', true)" class="btn-secondary">
                    <span>Versions</span>
                    @if ($this->page->versions->count() > 0)
                        <span class="rounded-full bg-zinc-200 dark:bg-zinc-700 px-1.5 py-0.2 text-[10px] font-bold">
                            {{ $this->page->versions->count() }}
                        </span>
                    @endif
                </button>

                <button type="button" wire:click="$set('showScheduleModal', true)" class="btn-secondary">
                    Schedule
                </button>

                <button
                    type="button"
                    wire:click="publishNow"
                    class="btn-primary"
                    data-test="publish-page-btn"
                >
                    Publish Changes
                </button>
            @endcan
        </div>
    </div>

    @if (session('builder-status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300">
            {{ session('builder-status') }}
        </div>
    @endif

    <!-- Builder Workspace (Two Columns: Sections List / Settings Inspector) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <!-- Left: Sections Structure (5 Cols) -->
        <div class="lg:col-span-5 space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                    Page Sections ({{ $this->page->sections->count() }})
                </h3>

                @can('pages.manage')
                    <button
                        type="button"
                        wire:click="openLibrary"
                        class="inline-flex items-center gap-1.5 text-xs font-bold text-amber-600 hover:text-amber-700 dark:text-amber-400 cursor-pointer"
                        data-test="add-section-btn"
                    >
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        <span>Add Section</span>
                    </button>
                @endcan
            </div>

            <!-- List of Sections -->
            <div class="space-y-2">
                @forelse ($this->page->sections as $index => $sec)
                    @php
                        $isSelected = ($this->editingSectionId === $sec->id);
                    @endphp
                    <div
                        class="rounded-xl border p-3.5 transition flex items-center justify-between gap-3 {{ $isSelected ? 'border-amber-500 bg-amber-50/50 dark:border-amber-500 dark:bg-amber-950/20 shadow-xs' : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900' }} {{ $sec->is_hidden ? 'opacity-60' : '' }}"
                    >
                        <div class="flex items-center gap-3 min-w-0 cursor-pointer flex-1" wire:click="editSection({{ $sec->id }})">
                            <span class="text-xs font-bold font-mono text-zinc-400">
                                #{{ $index + 1 }}
                            </span>
                            <div class="min-w-0">
                                <h4 class="text-sm font-bold text-zinc-900 dark:text-white truncate">
                                    {{ ucwords(str_replace('_', ' ', $sec->type)) }}
                                </h4>
                                <div class="flex items-center gap-2 text-[11px] text-zinc-500 dark:text-zinc-400">
                                    <span>{{ $sec->items->count() }} item(s)</span>
                                    <span>&bull;</span>
                                    <span>{{ $sec->visibility->label() }}</span>
                                    @if ($sec->is_hidden)
                                        <span class="text-rose-500 font-semibold">[Hidden]</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <!-- Action Icons -->
                        <div class="flex items-center gap-1 shrink-0">
                            @can('pages.manage')
                                <button type="button" wire:click="moveSectionUp({{ $sec->id }})" title="Move up" class="p-1 text-zinc-400 hover:text-zinc-800 dark:hover:text-white">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/></svg>
                                </button>
                                <button type="button" wire:click="moveSectionDown({{ $sec->id }})" title="Move down" class="p-1 text-zinc-400 hover:text-zinc-800 dark:hover:text-white">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                </button>
                                <button type="button" wire:click="toggleHideSection({{ $sec->id }})" title="{{ $sec->is_hidden ? 'Show section' : 'Hide section' }}" class="p-1 text-zinc-400 hover:text-zinc-800 dark:hover:text-white">
                                    @if ($sec->is_hidden)
                                        <svg class="size-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                                    @else
                                        <svg class="size-4 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    @endif
                                </button>
                                <button type="button" wire:click="duplicateSection({{ $sec->id }})" title="Duplicate section" class="p-1 text-zinc-400 hover:text-zinc-800 dark:hover:text-white">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9H7.875c-.621 0-1.125.504-1.125 1.125v3.375m7.5 10.376h-3.375a1.125 1.125 0 01-1.125-1.125V15"/></svg>
                                </button>
                                <button type="button" wire:click="deleteSection({{ $sec->id }})" title="Delete section" class="p-1 text-red-500 hover:text-red-700">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            @endcan
                        </div>
                    </div>
                @empty
                    <div class="rounded-2xl border-2 border-dashed border-zinc-200 p-8 text-center dark:border-zinc-800 space-y-3">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            No sections added to this page yet.
                        </p>
                        @can('pages.manage')
                            <button type="button" wire:click="openLibrary" class="btn-primary text-xs">
                                Choose Section From Library
                            </button>
                        @endcan
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Right: Section Editor Panel (7 Cols) -->
        <div class="lg:col-span-7">
            @php
                $activeSection = $this->editingSectionId ? ContentSection::with(['items.desktopMedia', 'items.mobileMedia'])->find($this->editingSectionId) : null;
            @endphp

            @if ($activeSection)
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-6">
                    <div class="flex items-center justify-between border-b border-zinc-100 pb-4 dark:border-zinc-800">
                        <div>
                            <h3 class="text-base font-bold text-zinc-900 dark:text-white">
                                Edit: {{ ucwords(str_replace('_', ' ', $activeSection->type)) }}
                            </h3>
                            <p class="text-xs text-zinc-400">Configure layout parameters, schedules, and child items.</p>
                        </div>

                        <button
                            type="button"
                            wire:click="saveEditingSection"
                            class="btn-primary"
                            data-test="save-section-btn"
                        >
                            Save Section
                        </button>
                    </div>

                    @if (session('section-saved'))
                        <div class="rounded-lg bg-emerald-50 border border-emerald-200 p-3 text-xs text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                            {{ session('section-saved') }}
                        </div>
                    @endif

                    <!-- Section Metadata (Visibility & Scheduling) -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Visibility</label>
                            <select
                                wire:model="editingVisibility"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            >
                                <option value="all">All Devices</option>
                                <option value="desktop">Desktop Only</option>
                                <option value="mobile">Mobile Only</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Starts At (Optional)</label>
                            <input
                                type="datetime-local"
                                wire:model="editingStartsAt"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Ends At (Optional)</label>
                            <input
                                type="datetime-local"
                                wire:model="editingEndsAt"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            />
                        </div>
                    </div>

                    <!-- Type Specific Settings -->
                    <div class="space-y-4">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            Section Settings
                        </h4>

                        @if ($activeSection->type === 'hero_carousel')
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <label class="flex items-center gap-2 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                    <input type="checkbox" wire:model="editingSettings.autoplay" class="rounded border-zinc-300" />
                                    <span>Enable Autoplay</span>
                                </label>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Slide Interval (ms)</label>
                                    <input type="number" wire:model="editingSettings.interval_ms" min="2000" max="15000" step="500" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                </div>
                            </div>
                        @elseif ($activeSection->type === 'image_tile_grid')
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div class="sm:col-span-3">
                                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Grid Heading</label>
                                    <input type="text" wire:model="editingSettings.heading" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Columns</label>
                                    <select wire:model="editingSettings.columns" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                                        <option value="2">2 Columns</option>
                                        <option value="3">3 Columns</option>
                                        <option value="4">4 Columns</option>
                                        <option value="6">6 Columns</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Aspect Ratio</label>
                                    <select wire:model="editingSettings.aspect_ratio" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                                        <option value="1:1">Square (1:1)</option>
                                        <option value="4:3">Standard (4:3)</option>
                                        <option value="16:9">Wide (16:9)</option>
                                    </select>
                                </div>
                            </div>
                        @elseif ($activeSection->type === 'category_nav')
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Heading</label>
                                    <input type="text" wire:model="editingSettings.heading" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Display Style</label>
                                    <select wire:model="editingSettings.display_style" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                                        <option value="circular">Circular Badges</option>
                                        <option value="card">Cards with Icons</option>
                                        <option value="pill">Horizontal Pills</option>
                                    </select>
                                </div>
                            </div>
                        @elseif ($activeSection->type === 'promo_card_carousel')
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Section Title *</label>
                                    <input type="text" wire:model="editingSettings.heading" required class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Badge Text</label>
                                    <input type="text" wire:model="editingSettings.badge_text" placeholder="e.g. Hot Deals" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                </div>
                                <div class="sm:col-span-3">
                                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Subheading</label>
                                    <input type="text" wire:model="editingSettings.subheading" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                </div>
                            </div>
                        @elseif ($activeSection->type === 'countdown')
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Headline *</label>
                                    <input type="text" wire:model="editingSettings.headline" required class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">End Timestamp (UTC) *</label>
                                    <input type="datetime-local" wire:model="editingSettings.end_time" required class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Badge Text</label>
                                    <input type="text" wire:model="editingSettings.badge" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Subheadline</label>
                                    <input type="text" wire:model="editingSettings.subheadline" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                </div>
                            </div>
                        @elseif ($activeSection->type === 'rich_text')
                            <div>
                                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Markdown Content *</label>
                                <textarea
                                    wire:model="editingSettings.content"
                                    rows="10"
                                    required
                                    placeholder="Write markdown here (# Heading, **bold**, lists)..."
                                    class="mt-1 block w-full rounded-lg border border-zinc-300 font-mono text-xs p-3 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                ></textarea>
                            </div>
                        @endif
                    </div>

                    <!-- Items Management (For sections with items) -->
                    @if ($activeSection->type !== 'rich_text')
                        <div class="space-y-4 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                            <div class="flex items-center justify-between">
                                <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                    Section Items ({{ $activeSection->items->count() }})
                                </h4>

                                <button
                                    type="button"
                                    wire:click="addItemToEditingSection"
                                    class="inline-flex items-center gap-1 text-xs font-bold text-amber-600 hover:text-amber-700 dark:text-amber-400 cursor-pointer"
                                    data-test="add-item-btn"
                                >
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                    <span>Add Item</span>
                                </button>
                            </div>

                            <div class="space-y-4">
                                @forelse ($activeSection->items as $iIndex => $item)
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-800/40 space-y-4">
                                        <div class="flex items-center justify-between border-b border-zinc-200 pb-2 dark:border-zinc-800">
                                            <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">
                                                Item #{{ $iIndex + 1 }}
                                            </span>
                                            <button type="button" wire:click="deleteItem({{ $item->id }})" class="text-xs text-red-500 hover:text-red-700">
                                                Remove Item
                                            </button>
                                        </div>

                                        <!-- Media Pickers (Desktop & Mobile) -->
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <!-- Desktop Media Slot -->
                                            <div class="space-y-1">
                                                <span class="block text-[11px] font-semibold text-zinc-600 dark:text-zinc-400">Desktop Image *</span>
                                                @if ($item->desktopMedia)
                                                    <div class="relative h-20 w-full overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                                                        <img src="{{ $item->desktopMedia->url() }}" alt="{{ $item->desktopMedia->alt_text }}" class="size-full object-cover" />
                                                        <button type="button" wire:click="removeMediaFromSlot({{ $item->id }}, 'desktop')" class="absolute top-1 right-1 rounded bg-black/60 p-1 text-white hover:bg-black">
                                                            <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                        </button>
                                                    </div>
                                                @else
                                                    <button type="button" wire:click="openMediaPicker({{ $item->id }}, 'desktop')" class="flex h-20 w-full items-center justify-center rounded-lg border-2 border-dashed border-zinc-300 bg-white text-xs text-zinc-500 hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                                                        + Select Image
                                                    </button>
                                                @endif
                                            </div>

                                            <!-- Mobile Media Slot (Optional) -->
                                            <div class="space-y-1">
                                                <span class="block text-[11px] font-semibold text-zinc-600 dark:text-zinc-400">Mobile Image (Falls back to Desktop)</span>
                                                @if ($item->mobileMedia)
                                                    <div class="relative h-20 w-full overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                                                        <img src="{{ $item->mobileMedia->url() }}" alt="{{ $item->mobileMedia->alt_text }}" class="size-full object-cover" />
                                                        <button type="button" wire:click="removeMediaFromSlot({{ $item->id }}, 'mobile')" class="absolute top-1 right-1 rounded bg-black/60 p-1 text-white hover:bg-black">
                                                            <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                        </button>
                                                    </div>
                                                @else
                                                    <button type="button" wire:click="openMediaPicker({{ $item->id }}, 'mobile')" class="flex h-20 w-full items-center justify-center rounded-lg border-2 border-dashed border-zinc-300 bg-white text-xs text-zinc-500 hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                                                        + Mobile Specific Image
                                                    </button>
                                                @endif
                                            </div>
                                        </div>

                                        <!-- Link Target Picker Slot -->
                                        <div>
                                            <span class="block text-[11px] font-semibold text-zinc-600 dark:text-zinc-400">Click Action / Target Link</span>
                                            @if ($item->link_type)
                                                <div class="mt-1 flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-2 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                                                    <span class="font-mono text-zinc-700 dark:text-zinc-300 truncate">
                                                        [{{ $item->link_type->label() }}] {{ $item->link_id ?: $item->link_url }}
                                                    </span>
                                                    <button type="button" wire:click="removeLink({{ $item->id }})" class="text-red-500 hover:text-red-700 ml-2">
                                                        Remove
                                                    </button>
                                                </div>
                                            @else
                                                <button type="button" wire:click="openLinkPicker({{ $item->id }})" class="mt-1 inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                                    <span>Set Link Target (Category, Brand, Product...)</span>
                                                </button>
                                            @endif
                                        </div>

                                        <!-- Item Text Fields -->
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                                            <div>
                                                <label class="block text-[11px] font-semibold text-zinc-600 dark:text-zinc-400">Label / Heading</label>
                                                <input
                                                    type="text"
                                                    value="{{ $item->settings['heading'] ?? ($item->settings['label'] ?? ($item->settings['custom_label'] ?? '')) }}"
                                                    wire:change="updateItemSetting({{ $item->id }}, 'heading', $event.target.value)"
                                                    class="mt-0.5 block w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-semibold text-zinc-600 dark:text-zinc-400">Button / Subtitle</label>
                                                <input
                                                    type="text"
                                                    value="{{ $item->settings['cta_text'] ?? ($item->settings['subheading'] ?? ($item->settings['sublabel'] ?? '')) }}"
                                                    wire:change="updateItemSetting({{ $item->id }}, 'cta_text', $event.target.value)"
                                                    class="mt-0.5 block w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="p-4 text-center text-xs text-zinc-400">
                                        No items added yet. Click "+ Add Item" above.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    @endif
                </div>
            @else
                <div class="rounded-2xl border border-zinc-200 bg-white p-12 text-center text-zinc-400 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-sm">Select a section from the left panel to inspect and configure its settings.</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Section Library Modal -->
    @if ($showLibraryModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Add Section from Library</h3>
                    <button type="button" wire:click="$set('showLibraryModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-white">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-[70vh] overflow-y-auto pr-1">
                    @php
                        $types = app(\App\Content\Sections\SectionTypeRegistry::class)->all();
                    @endphp
                    @foreach ($types as $typeDef)
                        <div
                            wire:click="addSection('{{ $typeDef->type() }}')"
                            class="p-4 rounded-xl border border-zinc-200 bg-white hover:border-amber-500 hover:shadow-xs transition cursor-pointer dark:border-zinc-800 dark:bg-zinc-800/50 space-y-1"
                            data-test="library-type-{{ $typeDef->type() }}"
                        >
                            <h4 class="text-sm font-bold text-zinc-900 dark:text-white">
                                {{ $typeDef->label() }}
                            </h4>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 line-clamp-2">
                                {{ $typeDef->description() }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <!-- Media Library Modal (Browse & Upload) -->
    @if ($showMediaModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-3xl rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <div class="flex items-center gap-3">
                        <button type="button" wire:click="$set('mediaModalTab', 'browse')" class="text-sm font-bold pb-1 border-b-2 {{ $mediaModalTab === 'browse' ? 'border-amber-500 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-400 hover:text-zinc-600' }}">
                            Media Library
                        </button>
                        <button type="button" wire:click="$set('mediaModalTab', 'upload')" class="text-sm font-bold pb-1 border-b-2 {{ $mediaModalTab === 'upload' ? 'border-amber-500 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-400 hover:text-zinc-600' }}">
                            Upload New
                        </button>
                    </div>
                    <button type="button" wire:click="$set('showMediaModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-white">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                @if ($mediaDeleteError)
                    <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-300">
                        {{ $mediaDeleteError }}
                    </div>
                @endif

                @if ($mediaModalTab === 'upload')
                    <form wire:submit="uploadNewMedia" class="space-y-4 py-2">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Select Image (PNG, JPG, WebP &le; 5MB) *</label>
                            <input
                                type="file"
                                wire:model="uploadedImage"
                                accept="image/png,image/jpeg,image/webp"
                                required
                                class="mt-1 block w-full text-xs text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-zinc-900 file:text-white hover:file:bg-zinc-800 dark:file:bg-white dark:file:text-zinc-900"
                            />
                            @error('uploadedImage') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Alt Text (Required for accessibility) *</label>
                            <input
                                type="text"
                                wire:model="mediaAltText"
                                required
                                placeholder="Describe the image content..."
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            />
                            @error('mediaAltText') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div class="flex justify-end pt-2">
                            <button type="submit" class="btn-primary" wire:loading.attr="disabled">
                                <span wire:loading.remove>Upload & Select</span>
                                <span wire:loading>Processing WebP...</span>
                            </button>
                        </div>
                    </form>
                @else
                    <!-- Browse Tab -->
                    <div class="grid grid-cols-3 sm:grid-cols-6 gap-3 max-h-[60vh] overflow-y-auto p-1">
                        @forelse ($this->availableMedia as $media)
                            <div class="group relative aspect-square rounded-xl border border-zinc-200 overflow-hidden bg-zinc-100 dark:border-zinc-800 dark:bg-zinc-800">
                                <img src="{{ $media->url() }}" alt="{{ $media->alt_text }}" class="size-full object-cover" />
                                <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition flex flex-col items-center justify-center gap-2 p-2">
                                    <button
                                        type="button"
                                        wire:click="selectMedia({{ $media->id }})"
                                        class="rounded bg-amber-500 text-white font-bold text-[10px] px-2 py-1 shadow hover:bg-amber-600"
                                    >
                                        Select
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="deleteMediaAsset({{ $media->id }})"
                                        class="text-[9px] text-red-300 hover:text-red-100"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="col-span-full py-12 text-center text-xs text-zinc-400">
                                No media uploaded yet. Switch to "Upload New" above.
                            </div>
                        @endforelse
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Link Target Picker Modal -->
    @if ($showLinkModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Set Target Link</h3>
                    <button type="button" wire:click="$set('showLinkModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-white">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="flex items-center gap-2 border-b border-zinc-200 pb-2 dark:border-zinc-800">
                    @foreach (['category' => 'Category', 'brand' => 'Brand', 'product' => 'Product', 'page' => 'Page', 'url' => 'Custom URL'] as $tKey => $tLabel)
                        <button
                            type="button"
                            wire:click="$set('linkSearchType', '{{ $tKey }}')"
                            class="px-2.5 py-1 text-xs font-semibold rounded-md transition {{ $linkSearchType === $tKey ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
                        >
                            {{ $tLabel }}
                        </button>
                    @endforeach
                </div>

                @if ($linkSearchType === 'url')
                    <div class="space-y-3">
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Custom URL (Relative or External)</label>
                        <input
                            type="text"
                            wire:model="linkCustomUrl"
                            placeholder="https://example.com or /products?min_price=1000"
                            class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />
                        <button type="button" wire:click="applyCustomUrl" class="btn-primary w-full">
                            Apply Custom URL
                        </button>
                    </div>
                @else
                    <div class="space-y-3">
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="linkSearchQuery"
                            wire:keyup="searchLinkTargets"
                            placeholder="Type to search {{ $linkSearchType }}..."
                            class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />

                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800 max-h-48 overflow-y-auto">
                            @forelse ($linkSearchResults as $res)
                                <div
                                    wire:click="applyTargetLink('{{ $linkSearchType }}', '{{ $res['slug'] ?? ($res['upid'] ?? $res['id']) }}')"
                                    class="p-2.5 flex items-center justify-between hover:bg-zinc-50 dark:hover:bg-zinc-800 rounded-lg cursor-pointer text-xs"
                                >
                                    <span class="font-bold text-zinc-800 dark:text-zinc-200 truncate">
                                        {{ $res['title'] ?? $res['name'] }}
                                    </span>
                                    <span class="font-mono text-[10px] text-zinc-400">
                                        {{ $res['slug'] ?? ($res['upid'] ?? '') }}
                                    </span>
                                </div>
                            @empty
                                <div class="py-6 text-center text-xs text-zinc-400">
                                    {{ $linkSearchQuery ? 'No matching targets found.' : 'Search above to find target items.' }}
                                </div>
                            @endforelse
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Version History & Rollback Modal -->
    @if ($showVersionModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-xl rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Version History & Rollback</h3>
                    <button type="button" wire:click="$set('showVersionModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-white">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="divide-y divide-zinc-200 dark:divide-zinc-800 max-h-[60vh] overflow-y-auto">
                    @forelse ($this->page->versions as $ver)
                        @php
                            $isCurrentLive = ($this->page->published_version_id === $ver->id);
                        @endphp
                        <div class="py-3 flex items-center justify-between gap-3 text-xs">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-zinc-900 dark:text-white">Version #{{ $ver->version_number }}</span>
                                    @if ($isCurrentLive)
                                        <span class="rounded bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 font-bold px-1.5 py-0.2 text-[10px]">
                                            CURRENT PUBLISHED
                                        </span>
                                    @endif
                                </div>
                                <div class="text-[11px] text-zinc-400 mt-0.5">
                                    Published by {{ $ver->publisher?->name ?? 'System' }} on {{ format_datetime($ver->created_at) }}
                                </div>
                            </div>

                            @can('pages.publish')
                                @if (! $isCurrentLive)
                                    <button
                                        type="button"
                                        wire:click="rollbackToVersion({{ $ver->id }})"
                                        class="btn-secondary text-xs"
                                    >
                                        Rollback to this
                                    </button>
                                @endif
                            @endcan
                        </div>
                    @empty
                        <div class="py-8 text-center text-xs text-zinc-400">
                            No published version history available for this page yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    <!-- Scheduling Modal -->
    @if ($showScheduleModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 space-y-4">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Schedule Page Publish</h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    The automated background scheduler runs every minute and will publish this page once due.
                </p>

                <form wire:submit="schedulePublish" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Target Publish Date & Time *</label>
                        <input
                            type="datetime-local"
                            wire:model="scheduleDate"
                            required
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />
                        @error('scheduleDate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" wire:click="$set('showScheduleModal', false)" class="btn-secondary">
                            Cancel
                        </button>
                        <button type="submit" class="btn-primary">
                            Set Schedule
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
