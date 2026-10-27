<?php

namespace App\Branding\Contracts;

use App\Branding\DTOs\BrandingData;

interface BrandingLookupInterface
{
    /**
     * Get the cached branding settings DTO.
     */
    public function getSettings(): BrandingData;

    /**
     * Resolve plain-text brand name for an area or general marketplace.
     */
    public function name(?string $area = null): string;

    /**
     * Resolve marketplace name.
     */
    public function marketplaceName(): string;

    /**
     * Resolve seller area name.
     */
    public function sellerAreaName(): string;

    /**
     * Resolve admin area name.
     */
    public function adminAreaName(): string;

    /**
     * Resolve support email.
     */
    public function supportEmail(): ?string;

    /**
     * Resolve logo URL for area and dark mode.
     */
    public function logoUrl(?string $area = null, bool $dark = false, bool $absolute = false): ?string;

    /**
     * Resolve favicon URL.
     */
    public function faviconUrl(): string;
}
