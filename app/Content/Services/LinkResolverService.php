<?php

namespace App\Content\Services;

use App\Catalog\Contracts\CatalogReadInterface;
use App\Content\DTOs\ResolvedLinkData;
use App\Content\Enums\LinkType;
use App\Content\Models\ContentPage;

class LinkResolverService
{
    public function __construct(
        protected CatalogReadInterface $catalogRead
    ) {}

    /**
     * Resolve a link configuration into a ResolvedLinkData DTO.
     */
    public function resolve(?LinkType $linkType, ?string $linkId, ?string $linkUrl): ResolvedLinkData
    {
        if (! $linkType) {
            return new ResolvedLinkData(
                url: null,
                isValid: true,
                isExternal: false
            );
        }

        return match ($linkType) {
            LinkType::Category => $this->resolveCategory($linkId),
            LinkType::Brand => $this->resolveBrand($linkId),
            LinkType::Product => $this->resolveProduct($linkId),
            LinkType::Page => $this->resolvePage($linkId),
            LinkType::Search => $this->resolveSearch($linkUrl ?? $linkId),
            LinkType::Url => $this->resolveUrl($linkUrl),
        };
    }

    protected function resolveCategory(?string $linkId): ResolvedLinkData
    {
        if (blank($linkId)) {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'No category selected.');
        }

        $category = $this->catalogRead->findCategory($linkId);
        if (! $category) {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'Category not found or deleted.');
        }

        if (! $category['is_active']) {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'Category is inactive.');
        }

        $url = route('products.index', ['category' => $category['slug']]);

        return new ResolvedLinkData(url: $url, isValid: true, isExternal: false);
    }

    protected function resolveBrand(?string $linkId): ResolvedLinkData
    {
        if (blank($linkId)) {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'No brand selected.');
        }

        $brand = $this->catalogRead->findBrand($linkId);
        if (! $brand) {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'Brand not found or deleted.');
        }

        if (! $brand['is_active']) {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'Brand is not approved.');
        }

        $url = route('products.index', ['brand' => $brand['slug']]);

        return new ResolvedLinkData(url: $url, isValid: true, isExternal: false);
    }

    protected function resolveProduct(?string $linkId): ResolvedLinkData
    {
        if (blank($linkId)) {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'No product selected.');
        }

        $product = $this->catalogRead->findProduct($linkId);
        if (! $product) {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'Product not found or deleted.');
        }

        if (! $product['is_active']) {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'Product is not QC approved.');
        }

        if (! $product['upid']) {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'Product has no active variant UPID.');
        }

        $url = route('product.show', ['slug' => $product['slug'], 'upid' => $product['upid']]);

        return new ResolvedLinkData(url: $url, isValid: true, isExternal: false);
    }

    protected function resolvePage(?string $linkId): ResolvedLinkData
    {
        if (blank($linkId)) {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'No page selected.');
        }

        $page = ContentPage::where('id', $linkId)
            ->orWhere('slug', $linkId)
            ->first();

        if (! $page) {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'Page not found.');
        }

        $url = $page->isHome() ? route('home') : url('/pages/'.$page->slug);

        return new ResolvedLinkData(url: $url, isValid: true, isExternal: false);
    }

    protected function resolveSearch(?string $query): ResolvedLinkData
    {
        $term = trim((string) $query);
        $url = route('products.index', $term !== '' ? ['search' => $term] : []);

        return new ResolvedLinkData(url: $url, isValid: true, isExternal: false);
    }

    protected function resolveUrl(?string $url): ResolvedLinkData
    {
        $rawUrl = trim((string) $url);
        if ($rawUrl === '') {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'URL is empty.');
        }

        $isExternal = false;
        if (str_starts_with($rawUrl, 'http://') || str_starts_with($rawUrl, 'https://')) {
            $parsedHost = parse_url($rawUrl, PHP_URL_HOST);
            $appHost = parse_url(config('app.url'), PHP_URL_HOST);

            if ($parsedHost && $appHost && strtolower($parsedHost) !== strtolower($appHost)) {
                $isExternal = true;
            }
        }

        return new ResolvedLinkData(
            url: $rawUrl,
            isValid: true,
            isExternal: $isExternal,
            target: $isExternal ? '_blank' : null,
            rel: $isExternal ? 'noopener noreferrer' : null
        );
    }
}
