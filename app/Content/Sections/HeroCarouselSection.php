<?php

namespace App\Content\Sections;

use App\Content\Contracts\SectionTypeDefinitionInterface;

class HeroCarouselSection implements SectionTypeDefinitionInterface
{
    public function type(): string
    {
        return 'hero_carousel';
    }

    public function label(): string
    {
        return 'Hero Carousel';
    }

    public function description(): string
    {
        return 'Full-width rotating promotional banner carousel with eager first slide.';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function settingsRules(): array
    {
        return [
            'autoplay' => ['sometimes', 'boolean'],
            'interval_ms' => ['sometimes', 'integer', 'min:1500', 'max:15000'],
            'show_dots' => ['sometimes', 'boolean'],
            'show_arrows' => ['sometimes', 'boolean'],
        ];
    }

    public function itemRules(): array
    {
        return [
            'heading' => ['nullable', 'string', 'max:150'],
            'subheading' => ['nullable', 'string', 'max:250'],
            'cta_text' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function defaultSettings(): array
    {
        return [
            'autoplay' => true,
            'interval_ms' => 5000,
            'show_dots' => true,
            'show_arrows' => true,
        ];
    }

    public function defaultItemSettings(): array
    {
        return [
            'heading' => '',
            'subheading' => '',
            'cta_text' => 'Shop Now',
        ];
    }

    public function supportsItems(): bool
    {
        return true;
    }

    public function maxItems(): ?int
    {
        return 10;
    }

    public function bladeComponent(): string
    {
        return 'content.sections.hero-carousel';
    }

    public function upgradeSettings(array $settings, int $fromVersion): array
    {
        return $settings;
    }
}
