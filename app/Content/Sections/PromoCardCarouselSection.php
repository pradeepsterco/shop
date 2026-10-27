<?php

namespace App\Content\Sections;

use App\Content\Contracts\SectionTypeDefinitionInterface;

class PromoCardCarouselSection implements SectionTypeDefinitionInterface
{
    public function type(): string
    {
        return 'promo_card_carousel';
    }

    public function label(): string
    {
        return 'Promo Card Carousel';
    }

    public function description(): string
    {
        return 'Horizontal carousel of product promotion cards with live price and optional sponsored badge.';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function settingsRules(): array
    {
        return [
            'heading' => ['required', 'string', 'max:150'],
            'subheading' => ['nullable', 'string', 'max:250'],
            'badge_text' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function itemRules(): array
    {
        return [
            'override_title' => ['nullable', 'string', 'max:150'],
            'override_price' => ['nullable', 'integer', 'min:0'],
            'is_sponsored' => ['sometimes', 'boolean'],
        ];
    }

    public function defaultSettings(): array
    {
        return [
            'heading' => 'Top Deals of the Day',
            'subheading' => 'Handpicked deals with verified lowest prices',
            'badge_text' => 'Hot Deals',
        ];
    }

    public function defaultItemSettings(): array
    {
        return [
            'override_title' => '',
            'override_price' => null,
            'is_sponsored' => false,
        ];
    }

    public function supportsItems(): bool
    {
        return true;
    }

    public function maxItems(): ?int
    {
        return 20;
    }

    public function bladeComponent(): string
    {
        return 'content.sections.promo-card-carousel';
    }

    public function upgradeSettings(array $settings, int $fromVersion): array
    {
        return $settings;
    }
}
