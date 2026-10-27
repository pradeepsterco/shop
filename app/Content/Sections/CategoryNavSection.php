<?php

namespace App\Content\Sections;

use App\Content\Contracts\SectionTypeDefinitionInterface;

class CategoryNavSection implements SectionTypeDefinitionInterface
{
    public function type(): string
    {
        return 'category_nav';
    }

    public function label(): string
    {
        return 'Category Navigation';
    }

    public function description(): string
    {
        return 'Visual category icons or pills in circular, card, or pill presentation.';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function settingsRules(): array
    {
        return [
            'heading' => ['nullable', 'string', 'max:150'],
            'display_style' => ['sometimes', 'string', 'in:circular,card,pill'],
            'show_labels' => ['sometimes', 'boolean'],
        ];
    }

    public function itemRules(): array
    {
        return [
            'custom_label' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function defaultSettings(): array
    {
        return [
            'heading' => 'Popular Categories',
            'display_style' => 'circular',
            'show_labels' => true,
        ];
    }

    public function defaultItemSettings(): array
    {
        return [
            'custom_label' => '',
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
        return 'content.sections.category-nav';
    }

    public function upgradeSettings(array $settings, int $fromVersion): array
    {
        return $settings;
    }
}
