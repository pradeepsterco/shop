<?php

namespace App\SEO;

use Illuminate\Support\Str;

readonly class SeoData
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $canonical = null,
        public string $robots = 'index, follow, max-image-preview:large',
        public string $ogType = 'website',
        public ?string $ogImage = null,
        public ?string $ogImageAlt = null,
    ) {}

    /**
     * Factory method with sensible fallbacks and sanitization.
     */
    public static function make(
        ?string $title = null,
        ?string $description = null,
        ?string $canonical = null,
        ?string $robots = null,
        ?string $ogType = null,
        ?string $ogImage = null,
        ?string $ogImageAlt = null,
    ): self {
        return new self(
            title: $title ? trim($title) : null,
            description: self::sanitizeDescription($description),
            canonical: self::sanitizeCanonicalUrl($canonical),
            robots: $robots ? trim($robots) : 'index, follow, max-image-preview:large',
            ogType: $ogType ?: 'website',
            ogImage: $ogImage ? trim($ogImage) : null,
            ogImageAlt: $ogImageAlt ? trim($ogImageAlt) : null,
        );
    }

    /**
     * Build standard formatted page title without duplicate brand suffix.
     */
    public function formattedTitle(?string $brand = null): string
    {
        $brandName = $brand ?: (function_exists('brand_name') ? brand_name() : config('app.name', 'Marketplace'));

        if (blank($this->title)) {
            return $brandName;
        }

        // If the title already contains or ends with the brand name, don't duplicate it
        if (Str::endsWith($this->title, $brandName) || Str::contains($this->title, " - {$brandName}")) {
            return $this->title;
        }

        return "{$this->title} - {$brandName}";
    }

    /**
     * Sanitize description: strip HTML tags/markdown, normalize spaces,
     * and truncate cleanly at a word boundary around 155 characters.
     */
    public static function sanitizeDescription(?string $text, int $limit = 155): ?string
    {
        if (blank($text)) {
            return null;
        }

        // Strip HTML tags and entities
        $clean = strip_tags(html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Collapse multiple whitespaces/newlines to a single space
        $clean = preg_replace('/\s+/', ' ', trim($clean));

        if (mb_strlen($clean) <= $limit) {
            return $clean;
        }

        // Truncate at word boundary
        $truncated = mb_substr($clean, 0, $limit);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > ($limit - 30)) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated, ',;.:-') . '...';
    }

    /**
     * Sanitize canonical URL: strip tracking parameters (utm_*, fbclid, gclid).
     */
    public static function sanitizeCanonicalUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';

        $cleanUrl = "{$scheme}://{$host}{$port}{$path}";

        if (! empty($parts['query'])) {
            parse_str($parts['query'], $queryParams);

            // Strip tracking parameters
            $filteredParams = [];
            foreach ($queryParams as $key => $val) {
                if (
                    str_starts_with($key, 'utm_') ||
                    in_array($key, ['fbclid', 'gclid', 'ref', 'source'], true)
                ) {
                    continue;
                }
                $filteredParams[$key] = $val;
            }

            if (! empty($filteredParams)) {
                $cleanUrl .= '?' . http_build_query($filteredParams);
            }
        }

        return $cleanUrl;
    }
}

