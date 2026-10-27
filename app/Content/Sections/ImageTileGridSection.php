<?php

namespace App\Content\Sections;

use App\Content\Contracts\SectionTypeDefinitionInterface;

class ImageTileGridSection implements SectionTypeDefinitionInterface
{
    public function type(): string
    {
        return 'image_tile_grid';
    }

    public function label(): string
    {
        return 'Image Tile Grid';
    }

    public function description(): string
    {
        return 'Multi-column grid of image tiles with captions (e.g. Shop by Room, Gift Guides).';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function settingsRules(): array
    {
        return [
            'heading' => ['nullable', 'string', 'max:150'],
            'columns' => ['sometimes', 'integer', 'in:2,3,4,6'],
            'gap' => ['sometimes', 'string', 'in:sm,md,lg'],
            'aspect_ratio' => ['sometimes', 'string', 'in:1:1,4:3,16:9'],
        ];
    }

    public function itemRules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:100'],
            'sublabel' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function defaultSettings(): array
    {
        return [
            'heading' => 'Featured Collections',
            'columns' => 4,
            'gap' => 'md',
            'aspect_ratio' => '1:1',
        ];
    }

    public function defaultItemSettings(): array
    {
        return [
            'label' => 'Collection',
            'sublabel' => '',
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
        return 'content.sections.image-tile-grid';
    }

    public function upgradeSettings(array $settings, int $fromVersion): array
    {
        return $settings;
    }
}
