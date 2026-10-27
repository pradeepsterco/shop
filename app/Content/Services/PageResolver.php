<?php

namespace App\Content\Services;

use App\Catalog\Contracts\CatalogReadInterface;
use App\Content\DTOs\ResolvedItemData;
use App\Content\DTOs\ResolvedMediaData;
use App\Content\DTOs\ResolvedPageData;
use App\Content\DTOs\ResolvedSectionData;
use App\Content\Enums\LinkType;
use App\Content\Models\ContentMedia;
use App\Content\Models\ContentPage;
use App\Content\Models\ContentPageVersion;
use App\Content\Models\ContentSectionItem;
use App\Content\Sections\SectionTypeRegistry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PageResolver
{
    public function __construct(
        protected SectionTypeRegistry $typeRegistry,
        protected LinkResolverService $linkResolver,
        protected CatalogReadInterface $catalogRead,
        protected MarkdownSanitizer $markdownSanitizer
    ) {}

    /**
     * Resolve a page for presentation.
     *
     * @param  string  $mode  'storefront' or 'preview'
     */
    public function resolve(ContentPage $page, string $mode = 'storefront'): ?ResolvedPageData
    {
        if ($mode === 'preview') {
            // Live resolution directly from draft data
            $page->load(['sections.items.desktopMedia', 'sections.items.mobileMedia']);
            $resolved = $this->resolveFromPageModel($page, $mode);

            return $this->applyTimeFilters($resolved, now(), $mode);
        }

        // Storefront mode: must be published
        if (! $page->isPublished() && ! $page->published_version_id) {
            return null;
        }

        $cacheKey = "content.page.{$page->slug}.published";

        // Cache the resolved structure WITHOUT time filters (Requirement 1 & 3)
        /** @var ResolvedPageData $cachedStructure */
        $cachedStructure = Cache::remember($cacheKey, 86400, function () use ($page) {
            // Read from published version snapshot if available, or current published state
            $version = $page->published_version_id ? ContentPageVersion::find($page->published_version_id) : $page->publishedVersion;
            if ($version) {
                return $this->resolveFromSnapshot($version->snapshot, 'storefront');
            }

            $page->load(['sections.items.desktopMedia', 'sections.items.mobileMedia']);

            return $this->resolveFromPageModel($page, 'storefront');
        });

        // Apply time filters (starts_at/ends_at and countdown expiry) AFTER reading from cache
        $filteredPage = $this->applyTimeFilters($cachedStructure, now(), 'storefront');

        // Resolve live product data per request (Requirement 3: Never cache live price/stock/active state)
        return $this->enrichLiveProductData($filteredPage);
    }

    /**
     * Resolve page DTO from an Eloquent page model.
     */
    public function resolveFromPageModel(ContentPage $page, string $mode): ResolvedPageData
    {
        $resolvedSections = [];
        $visibleIndex = 0;

        foreach ($page->sections as $section) {
            // Check hidden state
            if ($section->is_hidden && $mode !== 'preview') {
                continue;
            }

            // Unknown section type guard
            if (! $this->typeRegistry->has($section->type)) {
                Log::warning("Skipping unknown section type '{$section->type}' on page ID {$page->id}");

                continue;
            }

            $definition = $this->typeRegistry->get($section->type);
            $settings = $definition->upgradeSettings($section->settings, $section->schema_version);

            // Special sanitization for rich_text
            $rawContent = $settings['content'] ?? $settings['content_markdown'] ?? null;
            if ($section->type === 'rich_text' && $rawContent !== null) {
                $settings['rendered_html'] = $this->markdownSanitizer->render((string) $rawContent);
            }

            $resolvedItems = [];
            foreach ($section->items as $item) {
                $resolvedItem = $this->resolveItem($item, $section->type, $mode);
                if ($resolvedItem !== null) {
                    $resolvedItems[] = $resolvedItem;
                }
            }

            $isEager = ($visibleIndex === 0 && in_array($section->type, ['hero_carousel', 'single_banner', 'wide_banner'], true));

            $resolvedSections[] = new ResolvedSectionData(
                id: (int) $section->id,
                type: $section->type,
                position: (int) $section->position,
                visibility: $section->visibility->value,
                settings: $settings,
                items: $resolvedItems,
                isEager: $isEager,
                startsAt: $section->starts_at?->toIso8601String(),
                endsAt: $section->ends_at?->toIso8601String()
            );

            $visibleIndex++;
        }

        return new ResolvedPageData(
            id: (int) $page->id,
            slug: (string) $page->slug,
            title: (string) $page->title,
            type: $page->type->value,
            seoTitle: $page->seo_title,
            seoDescription: $page->seo_description,
            sections: $resolvedSections
        );
    }

    /**
     * Resolve page DTO from an immutable published version JSON snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function resolveFromSnapshot(array $snapshot, string $mode): ResolvedPageData
    {
        $pageData = $snapshot['page'] ?? [];
        $sectionsData = $snapshot['sections'] ?? [];

        $resolvedSections = [];
        $visibleIndex = 0;

        foreach ($sectionsData as $sData) {
            $type = $sData['type'] ?? '';

            if (($sData['is_hidden'] ?? false) && $mode !== 'preview') {
                continue;
            }

            if (! $this->typeRegistry->has($type)) {
                Log::warning("Skipping unknown section type '{$type}' in snapshot for page {$pageData['slug']}");

                continue;
            }

            $definition = $this->typeRegistry->get($type);
            $settings = $definition->upgradeSettings($sData['settings'] ?? [], $sData['schema_version'] ?? 1);

            $rawContent = $settings['content'] ?? $settings['content_markdown'] ?? null;
            if ($type === 'rich_text' && $rawContent !== null) {
                $settings['rendered_html'] = $this->markdownSanitizer->render((string) $rawContent);
            }

            $resolvedItems = [];
            foreach ($sData['items'] ?? [] as $iData) {
                $desktopMedia = ! empty($iData['desktop_media_id'])
                    ? ContentMedia::find($iData['desktop_media_id'])
                    : null;
                $mobileMedia = ! empty($iData['mobile_media_id'])
                    ? ContentMedia::find($iData['mobile_media_id'])
                    : null;

                $linkType = ! empty($iData['link_type']) ? LinkType::tryFrom($iData['link_type']) : null;
                $resolvedLink = $this->linkResolver->resolve(
                    $linkType,
                    $iData['link_id'] ?? null,
                    $iData['link_url'] ?? null
                );

                if ($mode === 'storefront' && $linkType && ! $resolvedLink->isValid) {
                    continue; // Skip invalid links on storefront
                }

                $resolvedDesktopMedia = ($desktopMedia instanceof ContentMedia) ? $this->resolveMedia($desktopMedia) : null;
                $resolvedMobileMedia = ($mobileMedia instanceof ContentMedia) ? $this->resolveMedia($mobileMedia) : $resolvedDesktopMedia;

                $resolvedItems[] = new ResolvedItemData(
                    id: (int) ($iData['id'] ?? 0),
                    desktopMedia: $resolvedDesktopMedia,
                    mobileMedia: $resolvedMobileMedia,
                    link: $resolvedLink,
                    settings: $iData['settings'] ?? []
                );
            }

            $isEager = ($visibleIndex === 0 && in_array($type, ['hero_carousel', 'single_banner', 'wide_banner'], true));

            $resolvedSections[] = new ResolvedSectionData(
                id: (int) ($sData['id'] ?? 0),
                type: $type,
                position: (int) ($sData['position'] ?? $visibleIndex),
                visibility: (string) ($sData['visibility'] ?? 'all'),
                settings: $settings,
                items: $resolvedItems,
                isEager: $isEager,
                startsAt: $sData['starts_at'] ?? null,
                endsAt: $sData['ends_at'] ?? null
            );

            $visibleIndex++;
        }

        return new ResolvedPageData(
            id: (int) ($pageData['id'] ?? 0),
            slug: (string) ($pageData['slug'] ?? ''),
            title: (string) ($pageData['title'] ?? ''),
            type: (string) ($pageData['type'] ?? 'landing'),
            seoTitle: $pageData['seo_title'] ?? null,
            seoDescription: $pageData['seo_description'] ?? null,
            sections: $resolvedSections
        );
    }

    /**
     * Resolve a single section item.
     */
    protected function resolveItem(ContentSectionItem $item, string $sectionType, string $mode): ?ResolvedItemData
    {
        $linkType = $item->link_type;
        $resolvedLink = $this->linkResolver->resolve($linkType, $item->link_id, $item->link_url);

        // On storefront, omit item if target link is broken/disabled
        if ($mode === 'storefront' && $linkType && ! $resolvedLink->isValid) {
            return null;
        }

        $desktop = $item->desktopMedia ? $this->resolveMedia($item->desktopMedia) : null;
        // Mobile falls back to desktop if null
        $mobile = $item->mobileMedia ? $this->resolveMedia($item->mobileMedia) : $desktop;

        return new ResolvedItemData(
            id: (int) $item->id,
            desktopMedia: $desktop,
            mobileMedia: $mobile,
            link: $resolvedLink,
            settings: $item->settings ?? []
        );
    }

    /**
     * Resolve ContentMedia model to ResolvedMediaData DTO.
     */
    public function resolveMedia(ContentMedia $media): ResolvedMediaData
    {
        return new ResolvedMediaData(
            id: (int) $media->id,
            url: $media->url(),
            webpUrl: $media->webpUrl(),
            width: (int) $media->width,
            height: (int) $media->height,
            alt: (string) $media->alt_text,
            variants: $media->variants ?? []
        );
    }

    /**
     * Apply time-based filters to sections (starts_at, ends_at, and countdown expiry).
     */
    public function applyTimeFilters(ResolvedPageData $page, \DateTimeInterface $now, string $mode): ResolvedPageData
    {
        if ($mode === 'preview') {
            // Preview mode displays all sections regardless of schedules
            return $page;
        }

        $currentTime = Carbon::instance($now);
        $filteredSections = [];

        foreach ($page->sections as $section) {
            // 1. Check starts_at
            if ($section->startsAt && Carbon::parse($section->startsAt)->greaterThan($currentTime)) {
                continue;
            }

            // 2. Check ends_at
            if ($section->endsAt && Carbon::parse($section->endsAt)->lessThanOrEqualTo($currentTime)) {
                continue;
            }

            // 3. Check countdown expiry
            if ($section->type === 'countdown') {
                $endTimeStr = $section->settings['end_time'] ?? null;
                if ($endTimeStr) {
                    $endTime = Carbon::parse($endTimeStr);
                    if ($endTime->lessThanOrEqualTo($currentTime)) {
                        $behavior = $section->settings['expired_behavior'] ?? 'hide';
                        if ($behavior === 'hide') {
                            continue; // Hide section when countdown expired
                        }
                    }
                }
            }

            $filteredSections[] = $section;
        }

        return new ResolvedPageData(
            id: $page->id,
            slug: $page->slug,
            title: $page->title,
            type: $page->type,
            seoTitle: $page->seoTitle,
            seoDescription: $page->seoDescription,
            sections: $filteredSections
        );
    }

    /**
     * Enrich promo carousel items and products with live price/stock from CatalogReadInterface.
     */
    public function enrichLiveProductData(ResolvedPageData $page): ResolvedPageData
    {
        $enrichedSections = [];

        foreach ($page->sections as $section) {
            if ($section->type === 'promo_card_carousel') {
                $enrichedItems = [];
                foreach ($section->items as $item) {
                    $enrichedSettings = $item->settings;

                    // If item links to a product, query fresh live data
                    if ($item->link && $item->link->isValid && str_contains($item->link->url ?? '', '/p/')) {
                        $parsedPath = parse_url($item->link->url, PHP_URL_PATH);
                        $path = is_string($parsedPath) ? $parsedPath : '';
                        $parts = explode('/p/', $path);
                        $upid = end($parts);

                        if ($upid) {
                            $liveProduct = $this->catalogRead->findProduct($upid);
                            if ($liveProduct) {
                                $enrichedSettings['live_title'] = $liveProduct['title'];
                                $enrichedSettings['live_price'] = $liveProduct['selling_price'];
                                $enrichedSettings['live_mrp'] = $liveProduct['mrp'];
                                $enrichedSettings['live_discount'] = $liveProduct['discount_percent'];
                                $enrichedSettings['live_in_stock'] = $liveProduct['in_stock'];
                                $enrichedSettings['live_is_active'] = $liveProduct['is_active'];

                                if (! $liveProduct['is_active']) {
                                    continue; // Skip inactive products
                                }
                            }
                        }
                    }

                    $enrichedItems[] = new ResolvedItemData(
                        id: $item->id,
                        desktopMedia: $item->desktopMedia,
                        mobileMedia: $item->mobileMedia,
                        link: $item->link,
                        settings: $enrichedSettings
                    );
                }

                $enrichedSections[] = new ResolvedSectionData(
                    id: $section->id,
                    type: $section->type,
                    position: $section->position,
                    visibility: $section->visibility,
                    settings: $section->settings,
                    items: $enrichedItems,
                    isEager: $section->isEager,
                    startsAt: $section->startsAt,
                    endsAt: $section->endsAt
                );
            } else {
                $enrichedSections[] = $section;
            }
        }

        return new ResolvedPageData(
            id: $page->id,
            slug: $page->slug,
            title: $page->title,
            type: $page->type,
            seoTitle: $page->seoTitle,
            seoDescription: $page->seoDescription,
            sections: $enrichedSections
        );
    }
}
