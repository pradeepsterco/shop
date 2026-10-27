<?php

use App\Branding\Contracts\BrandingLookupInterface;

if (! function_exists('brand_name')) {
    /**
     * Get the plain-text branding name for an area or general marketplace.
     */
    function brand_name(?string $area = null): string
    {
        return app(BrandingLookupInterface::class)->name($area);
    }
}

if (! function_exists('brand_logo_url')) {
    /**
     * Get the URL for a branding logo (with area and dark mode support).
     */
    function brand_logo_url(?string $area = null, bool $dark = false, bool $absolute = false): ?string
    {
        return app(BrandingLookupInterface::class)->logoUrl($area, $dark, $absolute);
    }
}

if (! function_exists('brand_favicon_url')) {
    /**
     * Get the URL for the favicon (falls back to /favicon.ico).
     */
    function brand_favicon_url(): string
    {
        return app(BrandingLookupInterface::class)->faviconUrl();
    }
}

if (! function_exists('brand_support_email')) {
    /**
     * Get the marketplace support email.
     */
    function brand_support_email(): ?string
    {
        return app(BrandingLookupInterface::class)->supportEmail();
    }
}
