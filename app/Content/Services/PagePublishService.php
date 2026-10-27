<?php

namespace App\Content\Services;

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Models\ContentPage;
use App\Content\Models\ContentPageVersion;
use App\Content\Models\ContentSection;
use App\Content\Models\ContentSectionItem;
use App\Services\AdminActivityLogger;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PagePublishService
{
    /**
     * Publish a content page immediately.
     * Enforces single published home page and creates immutable version snapshot.
     */
    public function publish(ContentPage $page, ?int $adminId = null): ContentPageVersion
    {
        return DB::transaction(function () use ($page, $adminId) {
            // 1. Enforce single published "home" page
            if ($page->type === PageType::Home) {
                $otherHomes = ContentPage::where('type', PageType::Home)
                    ->where('id', '!=', $page->id)
                    ->where('status', PageStatus::Published)
                    ->get();

                foreach ($otherHomes as $otherHome) {
                    $otherHome->update(['status' => PageStatus::Draft]);
                    $this->clearCache($otherHome->slug);
                }
            }

            // 2. Build full immutable snapshot
            $page->load(['sections.items']);
            $snapshot = $this->buildSnapshot($page);

            // 3. Compute next version number
            $latestVersion = ContentPageVersion::where('page_id', $page->id)->max('version_number') ?? 0;
            $nextVersionNumber = $latestVersion + 1;

            // 4. Create version record
            $version = ContentPageVersion::create([
                'page_id' => $page->id,
                'version_number' => $nextVersionNumber,
                'snapshot' => $snapshot,
                'published_by' => $adminId,
            ]);

            // 5. Update page state
            $page->update([
                'status' => PageStatus::Published,
                'published_version_id' => $version->id,
                'scheduled_publish_at' => null,
            ]);

            // 6. Invalidate cache
            $this->clearCache($page->slug);

            // 7. Audit log
            AdminActivityLogger::log('content.page_published', $page, [
                'version_number' => $nextVersionNumber,
                'type' => $page->type->value,
                'slug' => $page->slug,
            ]);

            return $version;
        });
    }

    /**
     * Schedule a page for future publication.
     */
    public function schedule(ContentPage $page, \DateTimeInterface $publishAt, ?int $adminId = null): void
    {
        $page->update([
            'status' => PageStatus::Scheduled,
            'scheduled_publish_at' => $publishAt,
        ]);

        AdminActivityLogger::log('content.page_scheduled', $page, [
            'scheduled_at' => $publishAt->format('Y-m-d H:i:s'),
            'slug' => $page->slug,
        ]);
    }

    /**
     * Rollback a page to a previously published version snapshot as a new draft.
     */
    public function rollback(ContentPage $page, ContentPageVersion $version, ?int $adminId = null): void
    {
        if ($version->page_id !== $page->id) {
            throw new DomainException("Version #{$version->version_number} does not belong to page '{$page->title}'.");
        }

        DB::transaction(function () use ($page, $version) {
            $snapshot = $version->snapshot;

            // 1. Delete current draft sections and items
            ContentSection::where('page_id', $page->id)->delete();

            // 2. Restore sections and items from snapshot
            $sectionsData = $snapshot['sections'] ?? [];
            foreach ($sectionsData as $sIndex => $sData) {
                $section = ContentSection::create([
                    'page_id' => $page->id,
                    'type' => $sData['type'],
                    'schema_version' => $sData['schema_version'] ?? 1,
                    'position' => $sData['position'] ?? $sIndex,
                    'visibility' => $sData['visibility'] ?? 'all',
                    'starts_at' => $sData['starts_at'] ?? null,
                    'ends_at' => $sData['ends_at'] ?? null,
                    'is_hidden' => $sData['is_hidden'] ?? false,
                    'settings' => $sData['settings'] ?? [],
                ]);

                $itemsData = $sData['items'] ?? [];
                foreach ($itemsData as $iIndex => $iData) {
                    ContentSectionItem::create([
                        'section_id' => $section->id,
                        'position' => $iData['position'] ?? $iIndex,
                        'desktop_media_id' => $iData['desktop_media_id'] ?? null,
                        'mobile_media_id' => $iData['mobile_media_id'] ?? null,
                        'link_type' => $iData['link_type'] ?? null,
                        'link_id' => $iData['link_id'] ?? null,
                        'link_url' => $iData['link_url'] ?? null,
                        'settings' => $iData['settings'] ?? [],
                    ]);
                }
            }

            // 3. Keep as draft so admin can preview/edit restored version
            $page->update([
                'status' => PageStatus::Draft,
            ]);

            // 4. Clear cache
            $this->clearCache($page->slug);

            // 5. Audit log
            AdminActivityLogger::log('content.page_rollback', $page, [
                'restored_version' => $version->version_number,
                'slug' => $page->slug,
            ]);
        });
    }

    /**
     * Clear the cache for a published page slug.
     */
    public function clearCache(string $slug): void
    {
        Cache::forget("content.page.{$slug}.published");
    }

    /**
     * Build an array snapshot of a page.
     *
     * @return array<string, mixed>
     */
    public function buildSnapshot(ContentPage $page): array
    {
        $sections = [];

        foreach ($page->sections as $section) {
            $items = [];
            foreach ($section->items as $item) {
                $items[] = [
                    'id' => $item->id,
                    'position' => $item->position,
                    'desktop_media_id' => $item->desktop_media_id,
                    'mobile_media_id' => $item->mobile_media_id,
                    'link_type' => $item->link_type?->value,
                    'link_id' => $item->link_id,
                    'link_url' => $item->link_url,
                    'settings' => $item->settings,
                ];
            }

            $sections[] = [
                'id' => $section->id,
                'type' => $section->type,
                'schema_version' => $section->schema_version,
                'position' => $section->position,
                'visibility' => $section->visibility->value,
                'starts_at' => $section->starts_at?->toIso8601String(),
                'ends_at' => $section->ends_at?->toIso8601String(),
                'is_hidden' => (bool) $section->is_hidden,
                'settings' => $section->settings,
                'items' => $items,
            ];
        }

        return [
            'page' => [
                'id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->title,
                'type' => $page->type->value,
                'seo_title' => $page->seo_title,
                'seo_description' => $page->seo_description,
            ],
            'sections' => $sections,
        ];
    }
}
