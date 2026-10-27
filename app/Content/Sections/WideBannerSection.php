<?php

namespace App\Content\Sections;

use App\Content\Contracts\SectionTypeDefinitionInterface;

class WideBannerSection implements SectionTypeDefinitionInterface
{
    public function type(): string
    {
        return 'wide_banner';
    }

    public function label(): string
    {
        return 'Wide Banner';
    }

    public function description(): string
    {
        return 'Full-bleed landscape banner with optional background styling and text alignment.';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function settingsRules(): array
    {
        return [
            'background_color' => ['nullable', 'string', 'max:50'],
            'text_alignment' => ['sometimes', 'string', 'in:left,center,right'],
        ];
    }

    public function itemRules(): array
    {
        return [
            'badge_text' => ['nullable', 'string', 'max:50'],
            'heading' => ['nullable', 'string', 'max:150'],
            'subheading' => ['nullable', 'string', 'max:250'],
            'cta_text' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function defaultSettings(): array
    {
        return [
            'background_color' => '',
            'text_alignment' => 'left',
        ];
    }

    public function defaultItemSettings(): array
    {
        return [
            'badge_text' => '',
            'heading' => '',
            'subheading' => '',
            'cta_text' => 'Learn More',
        ];
    }

    public function supportsItems(): bool
    {
        return true;
    }

    public function maxItems(): ?int
    {
        return 1;
    }

    public function bladeComponent(): string
    {
        return 'content.sections.wide-banner';
    }

    public function upgradeSettings(array $settings, int $fromVersion): array
    {
        return $settings;
    }
}
