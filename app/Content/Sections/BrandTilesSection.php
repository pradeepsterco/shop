<?php

namespace App\Content\Sections;

use App\Content\Contracts\SectionTypeDefinitionInterface;

class BrandTilesSection implements SectionTypeDefinitionInterface
{
    public function type(): string
    {
        return 'brand_tiles';
    }

    public function label(): string
    {
        return 'Brand Tiles';
    }

    public function description(): string
    {
        return 'Grid or carousel of brand logos linking to brand stores.';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function settingsRules(): array
    {
        return [
            'heading' => ['nullable', 'string', 'max:150'],
            'display_mode' => ['sometimes', 'string', 'in:grid,carousel'],
            'columns' => ['sometimes', 'integer', 'in:4,5,6'],
        ];
    }

    public function itemRules(): array
    {
        return [
            'custom_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function defaultSettings(): array
    {
        return [
            'heading' => 'Featured Brands',
            'display_mode' => 'grid',
            'columns' => 6,
        ];
    }

    public function defaultItemSettings(): array
    {
        return [
            'custom_name' => '',
        ];
    }

    public function supportsItems(): bool
    {
        return true;
    }

    public function maxItems(): ?int
    {
        return 24;
    }

    public function bladeComponent(): string
    {
        return 'content.sections.brand-tiles';
    }

    public function upgradeSettings(array $settings, int $fromVersion): array
    {
        return $settings;
    }
}
