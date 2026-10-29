<?php

namespace App\Branding\DTOs;

use Illuminate\Support\Facades\Storage;

readonly class BrandingData
{
    /**
     * @param  array<string, string|null>  $logos
     */
    public function __construct(
        public string $marketplaceName,
        public string $shortName,
        public string $sellerAreaName,
        public string $adminAreaName,
        public ?string $supportEmail,
        public array $logos = [],
        public ?string $faviconPath = null,
    ) {}

    /**
     * Resolve plain text brand name for an area or general marketplace.
     */
    public function name(?string $area = null): string
    {
        return match ($area) {
            'seller' => filled($this->sellerAreaName) ? $this->sellerAreaName : "{$this->marketplaceName} Seller Center",
            'admin' => filled($this->adminAreaName) ? $this->adminAreaName : "{$this->marketplaceName} Admin Panel",
            'short' => filled($this->shortName) ? $this->shortName : $this->marketplaceName,
            default => $this->marketplaceName,
        };
    }

    /**
     * Resolve logo URL with fallbacks:
     * 1. Area specific dark -> area specific light
     * 2. Default dark -> default light
     * 3. Null (triggers text fallback)
     */
    public function logoUrl(?string $area = null, bool $dark = false, bool $absolute = false): ?string
    {
        $area = $area ? strtolower(trim($area)) : 'default';

        $resolvedPath = null;

        if ($area === 'email') {
            // Requirement 4: Emails use light email logo only; falls back to default logo
            $resolvedPath = $this->logos['email_logo_light'] ?? $this->logos['default_logo_light'] ?? null;
        } else {
            if ($dark) {
                // Area dark -> area light -> default dark -> default light
                $resolvedPath = $this->logos["{$area}_logo_dark"]
                    ?? $this->logos["{$area}_logo_light"]
                    ?? ($area !== 'default' ? ($this->logos['default_logo_dark'] ?? $this->logos['default_logo_light'] ?? null) : null);
            } else {
                // Area light -> default light
                $resolvedPath = $this->logos["{$area}_logo_light"]
                    ?? ($area !== 'default' ? ($this->logos['default_logo_light'] ?? null) : null);
            }
        }

        if (! $resolvedPath) {
            return null;
        }

        $url = Storage::disk('public')->url($resolvedPath);

        if ($absolute) {
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                $url = url($url);
            }

            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return $path ?: $url;
    }

    /**
     * Resolve favicon URL (falls back to /favicon.ico).
     */
    public function faviconUrl(): string
    {
        if (filled($this->faviconPath)) {
            $url = Storage::disk('public')->url($this->faviconPath);
            $path = parse_url($url, PHP_URL_PATH);

            return $path ?: $url;
        }

        return '/favicon.ico';
    }
}
